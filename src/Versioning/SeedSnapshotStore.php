<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use RuntimeException;

use function bin2hex;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function glob;
use function hash_equals;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function realpath;
use function rename;
use function rmdir;
use function sprintf;
use function str_starts_with;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Frozen built-in texts of a seed revision (`<snapshots_dir>/<revision>/manifest.json` + `<slug>.<locale>.html`).
 * Reads are default-deny: an invalid name, a path outside the directory, a missing / oversized / inconsistent entry
 * is "unknown", never "equal". Hashes are always recomputed from the files.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SeedSnapshotStore
{
    /** Manifest format marker. */
    public const string FORMAT = 'legalkit-seed-snapshot-v1';

    /** Manifest file name. */
    public const string MANIFEST = 'manifest.json';

    /** Largest body file read (bytes; 4 bytes per character of LegalDocument::BODY_MAX_LENGTH). */
    public const int MAX_BYTES = LegalDocument::BODY_MAX_LENGTH * 4;

    private const string LOCALE_PATTERN = '/^[A-Za-z0-9_-]{2,8}$/';

    /**
     * @param string $directory `seed.snapshots_dir`
     * @param LegalSeedHasher $hasher Text hash
     * @param string $bundleVersion Recorded in the manifest (informational)
     */
    public function __construct(
        private string $directory,
        private LegalSeedHasher $hasher,
        private string $bundleVersion = '1.4',
    ) {
    }

    /**
     * Snapshot directory of a revision (not checked for existence).
     *
     * @return string The path
     */
    public function path(string $revision): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $revision;
    }

    /**
     * Whether a readable snapshot of the revision exists.
     *
     * @return bool The answer
     */
    public function has(string $revision): bool
    {
        return $this->manifest($revision) !== null;
    }

    /**
     * Writes the snapshot of a revision atomically (temporary directory, then rename).
     *
     * @param array<string, array<string, array{title: string, body: string}>> $pages Slug => locale => sanitized seed
     *
     * @throws RuntimeException When the revision is invalid, already exists without $force, or a write fails
     *
     * @return int Number of entries written
     */
    public function write(string $revision, array $pages, bool $force = false): int
    {
        $this->assertRevision($revision);
        $target = $this->path($revision);
        if (is_link($target) || (file_exists($target) && !is_dir($target))) {
            throw new RuntimeException(sprintf('Seed snapshot "%s" is not a plain directory inside the snapshot directory: refusing to replace it.', $revision));
        }
        if (is_dir($target) && !$force) {
            throw new RuntimeException(sprintf('Seed snapshot "%s" already exists (use --force to replace it).', $revision));
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Cannot create the snapshot directory "%s".', $this->directory));
        }

        $temp = $this->directory . DIRECTORY_SEPARATOR . '.' . $revision . '.tmp-' . bin2hex(random_bytes(4));
        $this->mkdir($temp);
        $manifest = ['format' => self::FORMAT, 'revision' => $revision, 'hash' => LegalSeedHasher::VERSION, 'bundle' => $this->bundleVersion, 'pages' => []];
        $count    = 0;
        foreach ($pages as $slug => $locales) {
            foreach ($locales as $locale => $seed) {
                $this->assertName((string) $slug, (string) $locale);
                $this->put($temp . DIRECTORY_SEPARATOR . $slug . '.' . $locale . '.html', $seed['body']);
                $manifest['pages'][$slug][$locale] = ['title' => $seed['title'], 'hash' => $this->hasher->hash($seed['title'], $seed['body'])];
                ++$count;
            }
        }
        $this->put($temp . DIRECTORY_SEPARATOR . self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

        if (is_dir($target)) {
            $this->removeSnapshotDir($target);
        }
        if (!@rename($temp, $target)) {
            $this->removeSnapshotDir($temp);

            throw new RuntimeException(sprintf('Cannot move the seed snapshot into "%s".', $target));
        }

        return $count;
    }

    /**
     * One frozen entry, or null when it is unknown (missing, invalid, oversized or inconsistent).
     *
     * @return ?array{title: string, body: string, hash: string}
     */
    public function entry(string $revision, string $slug, string $locale): ?array
    {
        if (preg_match(Configuration::SLUG_PATTERN, $slug) !== 1 || preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            return null;
        }
        $manifest = $this->manifest($revision);
        $meta     = $manifest['pages'][$slug][$locale] ?? null;
        if (!is_array($meta) || !is_string($meta['title'] ?? null)) {
            return null;
        }
        $file = $this->contained($this->path($revision) . DIRECTORY_SEPARATOR . $slug . '.' . $locale . '.html', $revision);
        if ($file === null || is_link($file)) {
            return null;
        }
        $size = @filesize($file);
        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }
        $body = @file_get_contents($file);
        if (!is_string($body)) {
            return null;
        }
        $hash     = $this->hasher->hash($meta['title'], $body);
        $declared = $meta['hash'] ?? null;
        // A snapshot taken before sanitizer.heading_anchors was turned on declares the text without the ids.
        // A missing hash proves nothing, and a body with no text left after the sanitiser is not a text to compare with.
        if (!is_string($declared) || (!hash_equals($declared, $hash) && !hash_equals($declared, $this->hasher->hashWithoutAnchorIds($meta['title'], $body)))) {
            return null;
        }
        if (!LegalDocumentResolver::hasText($this->hasher->normalizeBody($body))) {
            return null;
        }

        return ['title' => $meta['title'], 'body' => $body, 'hash' => $hash];
    }

    /**
     * Hash of a frozen entry (null = unknown).
     *
     * @return ?string The hash
     */
    public function hash(string $revision, string $slug, string $locale): ?string
    {
        return $this->entry($revision, $slug, $locale)['hash'] ?? null;
    }

    /**
     * @return ?array{pages: array<mixed>}
     */
    private function manifest(string $revision): ?array
    {
        if (preg_match(Configuration::REVISION_PATTERN, $revision) !== 1) {
            return null;
        }
        $file = $this->contained($this->path($revision) . DIRECTORY_SEPARATOR . self::MANIFEST, $revision);
        if ($file === null || is_link($file)) {
            return null;
        }
        $size = @filesize($file);
        $json = $size !== false && $size <= self::MAX_BYTES ? @file_get_contents($file) : false;
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['revision'] ?? null) !== $revision || !is_array($data['pages'] ?? null)) {
            return null;
        }

        return ['pages' => $data['pages']];
    }

    /**
     * The real path of a file when it exists inside the revision directory, which is inside the snapshot directory.
     */
    private function contained(string $file, string $revision): ?string
    {
        $base = realpath($this->directory);
        $dir  = realpath($this->path($revision));
        $real = realpath($file);
        if ($base === false || $dir === false || $real === false || !is_file($real)) {
            return null;
        }
        if (!str_starts_with($dir, $base . DIRECTORY_SEPARATOR) || dirname($real) !== $dir) {
            return null;
        }

        return $real;
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match(Configuration::REVISION_PATTERN, $revision) !== 1) {
            throw new RuntimeException(sprintf('Invalid seed revision "%s" (pattern %s).', $revision, Configuration::REVISION_PATTERN));
        }
    }

    private function assertName(string $slug, string $locale): void
    {
        if (preg_match(Configuration::SLUG_PATTERN, $slug) !== 1 || preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            throw new RuntimeException(sprintf('Invalid snapshot entry "%s.%s".', $slug, $locale));
        }
    }

    private function mkdir(string $path): void
    {
        if (!@mkdir($path, 0o775) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Cannot create "%s".', $path));
        }
    }

    private function put(string $file, string $contents): void
    {
        if (@file_put_contents($file, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $file));
        }
    }

    /**
     * Removes a snapshot directory: only its manifest and *.html files, then the (empty) directory.
     */
    private function removeSnapshotDir(string $dir): void
    {
        foreach ([...(glob($dir . DIRECTORY_SEPARATOR . '*.html') ?: []), $dir . DIRECTORY_SEPARATOR . self::MANIFEST] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
