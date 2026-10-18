<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Stands in for CookieConsent's `nowo_cookie_consent_should_render()` (test kernels with the `consent_stub` option).
 */
final class ConsentStubTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('nowo_cookie_consent_should_render', static fn (): bool => true)];
    }
}
