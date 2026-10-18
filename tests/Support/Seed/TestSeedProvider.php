<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support\Seed;

use Nowo\LegalKitBundle\Seed\LegalSeedProviderInterface;

/**
 * Host seed provider of the test kernel (`test_seed` option): a notice without built-in markers in English and an
 * extra page "blank" whose body is empty once sanitised.
 */
final class TestSeedProvider implements LegalSeedProviderInterface
{
    public function supports(string $slug, string $locale): bool
    {
        return ($slug === 'notice' && $locale === 'en') || $slug === 'blank';
    }

    public function title(string $slug, string $locale): string
    {
        return $slug === 'blank' ? 'Blank' : 'Acme notice';
    }

    public function body(string $slug, string $locale): string
    {
        return $slug === 'blank' ? '<script>nothing()</script>' : '<p>Acme Ltd, 1 Main Street.</p>';
    }
}
