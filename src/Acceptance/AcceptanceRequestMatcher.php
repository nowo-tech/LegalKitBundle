<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Routing\LegalRouteLoader;
use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * Which requests the re-acceptance gate may redirect: exemptions (built-in, configured routes and paths, host voters)
 * and the HTML-navigation test. Anything ambiguous is not an HTML navigation.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AcceptanceRequestMatcher
{
    /** Built-in exempt route prefixes. */
    public const array BUILTIN_ROUTE_PREFIXES = [LegalRouteLoader::ACCEPTANCE_ROUTE, LegalRouteLoader::ADMIN_ROUTE_PREFIX, '_wdt', '_profiler'];

    /** Built-in exempt route names. */
    public const array BUILTIN_ROUTES = ['_error', '_preview_error'];

    /** @var list<LegalAcceptanceExemptionInterface|LegalAcceptanceRequestExemptionInterface> */
    private array $voters;

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages (their public routes are exempt)
     * @param list<string> $exemptRoutes Configured route names or "prefix*"
     * @param list<string> $exemptPaths Configured path prefixes
     * @param iterable<LegalAcceptanceExemptionInterface|LegalAcceptanceRequestExemptionInterface> $voters Host voters
     *                                                                                                     (1.12: the request-context form, and the deprecated one until 2.0)
     * @param iterable<LegalAcceptanceRequestExemptionInterface> $requestVoters Host voters tagged
     *                                                                          `nowo_legal_kit.acceptance_request_exemption` (1.12)
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private array $exemptRoutes = [],
        private array $exemptPaths = [],
        iterable $voters = [],
        iterable $requestVoters = [],
    ) {
        $list = [];
        foreach ([$voters, $requestVoters] as $group) {
            foreach ($group as $voter) {
                if (!in_array($voter, $list, true)) {
                    $list[] = $voter;
                }
            }
        }
        $this->voters = $list;
    }

    /**
     * Whether the request is never redirected.
     */
    public function isExempt(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (is_string($route) && ($this->isBuiltinRoute($route) || $this->matchesRoute($route))) {
            return true;
        }
        $path = $request->getPathInfo();
        foreach ($this->exemptPaths as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        $context = null;
        foreach ($this->voters as $voter) {
            if ($voter instanceof LegalAcceptanceRequestExemptionInterface) {
                if ($voter->isExemptRequest($context ??= self::context($request))) {
                    return true;
                }
            } elseif ($voter->isExempt($request)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The framework-free view of a request handed to {@see LegalAcceptanceRequestExemptionInterface} (1.12).
     */
    public static function context(Request $request): LegalRequestContext
    {
        $route      = $request->attributes->get('_route');
        $parameters = [];
        $raw        = $request->attributes->get('_route_params');
        foreach (is_array($raw) ? $raw : [] as $name => $value) {
            if (is_string($name) && (is_scalar($value) || $value === null)) {
                $parameters[$name] = $value;
            }
        }

        return new LegalRequestContext(
            $request->getPathInfo(),
            $request->getMethod(),
            is_string($route) ? $route : null,
            $parameters,
            (string) $request->getRequestFormat('html'),
            $request->isXmlHttpRequest(),
            $request->getLocale(),
        );
    }

    /**
     * Whether the request is a browser HTML navigation (Accept lists text/html; not XHR, Turbo frame / stream, or
     * another request format).
     */
    public static function isHtmlNavigation(Request $request): bool
    {
        if ($request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame') || $request->getRequestFormat() !== 'html') {
            return false;
        }
        $accept = strtolower(trim((string) $request->headers->get('Accept', '')));
        if (str_starts_with($accept, 'text/vnd.turbo-stream.html')) {
            return false;
        }

        return str_contains($accept, 'text/html');
    }

    private function isBuiltinRoute(string $route): bool
    {
        if (in_array($route, self::BUILTIN_ROUTES, true)) {
            return true;
        }
        foreach (self::BUILTIN_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }
        foreach ($this->catalog->pages() as $page) {
            if (in_array($route, [$page->routeName(), $page->unlocalizedRouteName(), $page->routeName() . LegalRouteLoader::BARE_SUFFIX], true)) {
                return true;
            }
        }

        return false;
    }

    private function matchesRoute(string $route): bool
    {
        foreach ($this->exemptRoutes as $exempt) {
            if (str_ends_with($exempt, '*') ? str_starts_with($route, substr($exempt, 0, -1)) : $route === $exempt) {
                return true;
            }
        }

        return false;
    }
}
