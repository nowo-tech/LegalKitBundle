<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_replace;
use function str_ends_with;

/**
 * No bundle template prints with |raw outside a Twig comment: legal HTML goes through |nowo_legal_kit_sanitize_html,
 * diff segments and change summaries are autoescaped (contract 1.4.0 §4.3 / §14; same rule as
 * .scripts/check-twig-extra.sh).
 */
final class TemplatesNoRawTest extends TestCase
{
    public function testNoRawFilterInBundleTemplates(): void
    {
        $checked = 0;
        $files   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Resources/views'));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            ++$checked;
            self::assertFalse(self::usesRaw((string) file_get_contents($file->getPathname())), $file->getPathname());
        }
        self::assertGreaterThan(10, $checked);
    }

    public function testTheRuleCatchesRawButNotComments(): void
    {
        self::assertTrue(self::usesRaw("{# never |raw #}\n{{ segment.text|raw }}"));
        self::assertTrue(self::usesRaw('{{ x | raw }}'));
        self::assertFalse(self::usesRaw("{#\n  never |raw here\n#}\n{{ segment.text }}"));
        self::assertFalse(self::usesRaw('{{ body|nowo_legal_kit_sanitize_html }} {{ rawText }}'));
    }

    private static function usesRaw(string $template): bool
    {
        return preg_match('/\|\s*raw\b/', (string) preg_replace('/\{#.*?#\}/s', '', $template)) === 1;
    }
}
