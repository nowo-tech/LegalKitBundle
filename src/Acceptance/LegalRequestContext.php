<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

/**
 * The request the re-acceptance gate is about to redirect, as the bundle hands it to
 * {@see LegalAcceptanceRequestExemptionInterface} (1.12, architecture bar rule 3): no HTTP type in the port.
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
     */
    public function __construct(
        public string $path,
        public string $method = 'GET',
        public ?string $route = null,
        public array $routeParameters = [],
        public string $format = 'html',
        public bool $xmlHttpRequest = false,
        public ?string $locale = null,
    ) {
    }
}
