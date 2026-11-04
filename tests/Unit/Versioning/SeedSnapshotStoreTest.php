<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Versioning;

use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use Nowo\LegalKitBundle\Versioning\SeedSnapshotStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;

/**
 * Seed snapshots: atomic write, default-deny reads (names, containment, size, consistency).
 */
final class SeedSnapshotStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-legal-kit-snapshots-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    private function store(): SeedSnapshotStore
    {
        return new SeedSnapshotStore($this->dir, new LegalSeedHasher(new DomAllowlistSanitizer()), '1.4.0');
    }

    public function testWriteAndRead(): void
    {
        $store = $this->store();
        self::assertFalse($store->has('2026-09-18'));
        self::assertSame(2, $store->write('2026-09-18', ['terms' => ['en' => ['title' => 'Terms', 'body' => '<p>Old</p>'], 'es' => ['title' => 'Condiciones', 'body' => '<p>Viejo</p>']]]));

        self::assertTrue($store->has('2026-09-18'));
        $entry = $store->entry('2026-09-18', 'terms', 'es');
        self::assertNotNull($entry);
        self::assertSame('Condiciones', $entry['title']);
        self::assertSame('<p>Viejo</p>', $entry['body']);
        self::assertSame((new LegalSeedHasher(new DomAllowlistSanitizer()))->hash('Condiciones', '<p>Viejo</p>'), $store->hash('2026-09-18', 'terms', 'es'));
        $manifest = json_decode((string) file_get_contents($this->dir . '/2026-09-18/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame(SeedSnapshotStore::FORMAT, $manifest['format']);
        self::assertSame('1.4.0', $manifest['bundle']);

        self::assertNull($store->entry('2026-09-18', 'terms', 'de'), 'Missing entry');
        self::assertNull($store->entry('2026-09-18', 'privacy', 'en'));
        self::assertNull($store->entry('2026-01-01', 'terms', 'en'), 'Missing revision');
        self::assertNull($store->entry('../2026-09-18', 'terms', 'en'), 'Traversal in the revision');
        self::assertNull($store->entry('2026-09-18', '../terms', 'en'), 'Traversal in the slug');
        self::assertNull($store->entry('2026-09-18', 'terms', 'e/n'));
    }

    public function testExistingRevisionNeedsForce(): void
    {
        $store = $this->store();
        $store->write('r1', ['terms' => ['en' => ['title' => 'A', 'body' => '<p>A</p>']]]);
        try {
            $store->write('r1', ['terms' => ['en' => ['title' => 'B', 'body' => '<p>B</p>']]]);
            self::fail('Overwrite without force');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }
        $store->write('r1', ['terms' => ['en' => ['title' => 'B', 'body' => '<p>B</p>']]], true);
        self::assertSame('B', $store->entry('r1', 'terms', 'en')['title'] ?? null);
    }

    public function testInvalidNamesAreRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->store()->write('../x', []);
    }

    public function testInvalidEntryNamesAreRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->store()->write('r1', ['Bad Slug' => ['en' => ['title' => 'A', 'body' => 'b']]]);
    }

    public function testTamperedOrInvalidFilesAreUnknown(): void
    {
        $store = $this->store();
        $store->write('r1', ['terms' => ['en' => ['title' => 'A', 'body' => '<p>A</p>'], 'es' => ['title' => 'B', 'body' => '<p>B</p>']]]);

        file_put_contents($this->dir . '/r1/terms.en.html', '<p>Changed</p>');
        self::assertNull($store->entry('r1', 'terms', 'en'), 'Hash mismatch with the manifest');
        self::assertNotNull($store->entry('r1', 'terms', 'es'));

        $manifest = json_decode((string) file_get_contents($this->dir . '/r1/manifest.json'), true);
        self::assertIsArray($manifest);
        unset($manifest['pages']['terms']['es']['hash']);
        file_put_contents($this->dir . '/r1/manifest.json', json_encode($manifest));
        self::assertNotNull($store->entry('r1', 'terms', 'es'), 'A hand-made manifest without hashes is accepted (recomputed)');

        file_put_contents($this->dir . '/r1/terms.es.html', str_repeat('a', SeedSnapshotStore::MAX_BYTES + 1));
        self::assertNull($store->entry('r1', 'terms', 'es'), 'Oversized');

        mkdir($this->dir . '/r2');
        file_put_contents($this->dir . '/r2/manifest.json', '{"format": "other", "revision": "r2", "pages": {}}');
        self::assertFalse($store->has('r2'));
        file_put_contents($this->dir . '/r2/manifest.json', 'not json');
        self::assertFalse($store->has('r2'));
        file_put_contents($this->dir . '/r2/manifest.json', '{"format": "legalkit-seed-snapshot-v1", "revision": "r3", "pages": {}}');
        self::assertFalse($store->has('r2'), 'Revision mismatch');
    }

    public function testSymlinksAreUnknown(): void
    {
        $store = $this->store();
        $store->write('r1', ['terms' => ['en' => ['title' => 'A', 'body' => '<p>A</p>']]]);
        $outside = $this->dir . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/terms.en.html', '<p>A</p>');
        @unlink($this->dir . '/r1/terms.en.html');
        symlink($outside . '/terms.en.html', $this->dir . '/r1/terms.en.html');

        self::assertNull($store->entry('r1', 'terms', 'en'));
        self::remove($outside);
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
