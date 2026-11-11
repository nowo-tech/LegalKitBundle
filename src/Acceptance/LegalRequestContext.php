<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

/**
 * The request the re-acceptance gate is about to redirect, as the bundle hands it to
 * {@see LegalAcceptanceRequestExemptionInterface} (1.12, architecture bar rule 3): no HTTP type in the port.
 *
 * Cookies (1.13): none, unless the host names them in `acceptance.context_cookies` (default: none); then only those
 * cookies, read through {@see cookie()}. The session cookie is never given, even when listed. The values are never
 * stored in a property of this object (they live in an internal {@see \WeakMap} keyed by it), so no dump,
 * `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of the context shows them; a `clone` or an
 * unserialized copy has none.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalRequestContext
{
    /**
     * @param string $path Path info (without the query string), e.g. `/app/mfa`
     * @param string $method HTTP method, upper case
     * @param ?string $route Matched route name (null when routing has not matched one)
     * @param array<string, scalar|null> $routeParameters Scalar route parameters (`_route_params`)
     * @param string $format Request format (`html`, `json`, ...)
     * @param bool $xmlHttpRequest Whether it is an XMLHttpRequest
     * @param ?string $locale Request locale
     * @param array<string, string> $cookies The allowlisted cookies the request sent (name => value; kept outside the
     *                                       object's properties)
     */
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public ?string $route = null,
        public array $routeParameters = [],
        public string $format = 'html',
        public bool $xmlHttpRequest = false,
        public ?string $locale = null,
        array $cookies = [],
    ) {
        if ($cookies !== []) {
            LegalRequestContextCookies::put($this, $cookies);
        }
    }

    /**
     * The value of a cookie the host named in `acceptance.context_cookies`, or null when it is not allowlisted or the
     * request did not send it (1.13).
     *
     * @param string $name Cookie name (exact)
     */
    public function cookie(string $name): ?string
    {
        return LegalRequestContextCookies::get($this, $name);
    }

    /**
     * Whether the host allowlisted this cookie and the request sent it (1.13).
     *
     * @param string $name Cookie name (exact)
     */
    public function hasCookie(string $name): bool
    {
        return LegalRequestContextCookies::has($this, $name);
    }
}
