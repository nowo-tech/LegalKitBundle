<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Sanitizer;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;

use function in_array;
use function parse_url;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function str_contains;
use function strtolower;

/**
 * URL policy of `href` / `src` in legal HTML: relative, `#`, `mailto:`, `tel:`, `http(s)` links; relative or `https`
 * images; never `javascript:` / `data:` / `vbscript:`. With OutboundUrlGuardBundle, absolute targets on private
 * networks or cloud metadata hosts are dropped too (no DNS lookup, no request).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class UrlPolicy
{
    private const array LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const array IMAGE_SCHEMES = ['https'];

    /**
     * @param OutboundUrlGuard|null $guard Optional SSRF guard (OutboundUrlGuardBundle)
     */
    public function __construct(
        private ?OutboundUrlGuard $guard = null,
    ) {
    }

    /**
     * Whether a link target (`a[href]`) is allowed.
     */
    public function isSafeLink(string $url): bool
    {
        return $this->isSafe($url, self::LINK_SCHEMES);
    }

    /**
     * Whether an image source (`img[src]`) is allowed.
     */
    public function isSafeImage(string $url): bool
    {
        return $this->isSafe($url, self::IMAGE_SCHEMES) && !str_starts_with($this->clean($url), '#');
    }

    /**
     * @param list<string> $schemes
     */
    private function isSafe(string $url, array $schemes): bool
    {
        $url = $this->clean($url);
        if ($url === '' || str_contains($url, '\\')) {
            // Browsers read "\" as "/" ("/\\evil.tld" = "//evil.tld"): refuse instead of guessing.
            return false;
        }
        if (str_starts_with($url, '//')) {
            // Protocol-relative: an absolute https target for the guard.
            return $this->allowedByGuard('https:' . $url);
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../') || str_starts_with($url, '?')) {
            return true;
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $match) !== 1) {
            // No scheme and no colon before the first "/", "?" or "#": a relative path ("page.html").
            return preg_match('/^[^:\/?#]+(?:[\/?#]|$)/', $url) === 1;
        }

        $scheme = strtolower($match[1]);
        if (!in_array($scheme, $schemes, true)) {
            return false;
        }
        if ($scheme === 'mailto' || $scheme === 'tel') {
            return true;
        }
        $host = parse_url($url, \PHP_URL_HOST);

        return is_string($host) && $host !== '' && $this->allowedByGuard($url);
    }

    private function allowedByGuard(string $absoluteUrl): bool
    {
        if (!$this->guard instanceof OutboundUrlGuard) {
            return true;
        }

        return $this->guard->inspect($absoluteUrl, resolveDns: false)->isValid();
    }

    /**
     * Trims whitespace and removes ASCII control characters (browsers ignore them inside schemes: "java\tscript:").
     */
    private function clean(string $url): string
    {
        return trim((string) preg_replace('/[\x00-\x20\x7F]+/', '', $url));
    }
}
