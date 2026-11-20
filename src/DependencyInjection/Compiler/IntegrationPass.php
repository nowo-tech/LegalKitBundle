<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\EventListener\ActionLogListener;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Versioning\LegalSeedHasher;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Throwable;

use function is_array;
use function is_string;
use function parse_url;
use function str_contains;

use const PHP_URL_HOST;

/**
 * Wires integrations known only once every extension is loaded: the AuditKit action log listener (when the host
 * enables the action log) and the CookieConsent settings the doctor command checks.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class IntegrationPass implements CompilerPassInterface
{
    /** Route requirement used when the locales come from an env var (unknown until runtime); the controllers 404 any locale that is not enabled. */
    public const string GENERIC_LOCALE_REQUIREMENT = '[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,4})?';

    /** First framework-bundle version that deprecates the `router.request_context.*` parameters (8.1). */
    private const int DEPRECATED_REQUEST_CONTEXT_PARAMETERS_FROM = 80100;

    /**
     * @param int|null $kernelVersionId Symfony version id (tests); null = the running kernel's
     */
    public function __construct(private ?int $kernelVersionId = null)
    {
        $this->kernelVersionId ??= Kernel::VERSION_ID;
    }

    /**
     * Wires the optional integrations: the action log listener, the locale route requirement and the doctor command inputs read from CookieConsent.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ActionLogRecorder::class) && !$container->hasDefinition(ActionLogListener::class)) {
            $container->register(ActionLogListener::class, ActionLogListener::class)
                ->setArguments([new Reference(ActionLogRecorder::class), $container->hasParameter('nowo_legal_kit.acceptance.action_log') && (bool) $container->getParameter('nowo_legal_kit.acceptance.action_log')])
                // Registered only when the host enables the action log: tagged here (compiler-pass registration).
                ->addTag('kernel.event_subscriber');
        }

        $container->setParameter('nowo_legal_kit.locale_requirement', $this->localeRequirement($container));

        // Versioning: absolute links on the router host count as internal LegalKit links in the seed hash.
        if ($container->hasDefinition(LegalSeedHasher::class)) {
            $host = $this->routerHost($container);
            $container->getDefinition(LegalSeedHasher::class)->setArgument('$host', $host);
        }

        if (!$container->hasDefinition(DoctorCommand::class)) {
            return;
        }
        $doctor = $container->getDefinition(DoctorCommand::class);
        if ($container->hasParameter('nowo_cookie_consent.disabled_routes')) {
            $routes = $container->getParameter('nowo_cookie_consent.disabled_routes');
            $doctor->setArgument('$disabledRoutes', is_array($routes) ? array_values(array_filter($routes, is_string(...))) : []);
        }
        if ($container->hasParameter('nowo_cookie_consent.privacy_route')) {
            $route = $container->getParameter('nowo_cookie_consent.privacy_route');
            $doctor->setArgument('$privacyRoute', is_string($route) && $route !== '' ? $route : null);
        }
    }

    /**
     * The host the router is configured with, without reading the `router.request_context.host` parameter on
     * framework-bundle 8.1+ (it is deprecated there): `framework.router.default_uri`, parsed at compile time. Before 8.1
     * the parameter is still the source (it also carries a host set by hand, and the `localhost` default), so nothing
     * changes there. A default URI that is an env var or parameter placeholder is unknown until runtime: null (relative
     * links only), as an unusable host was before.
     */
    private function routerHost(ContainerBuilder $container): ?string
    {
        if ($this->kernelVersionId < self::DEPRECATED_REQUEST_CONTEXT_PARAMETERS_FROM && $container->hasParameter('router.request_context.host')) {
            $host = $container->getParameter('router.request_context.host');

            return is_string($host) && $host !== '' ? $host : null;
        }

        $defaultUri = null;
        foreach ($container->getExtensionConfig('framework') as $config) {
            $router = $config['router'] ?? null;
            if (is_array($router) && isset($router['default_uri']) && is_string($router['default_uri'])) {
                $defaultUri = $router['default_uri'];
            }
        }
        if ($defaultUri === null || str_contains($defaultUri, '%')) {
            return null;
        }
        $host = parse_url($defaultUri, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Requirement of the admin `{locale}`: the enabled locales once the host parameters are known.
     */
    private function localeRequirement(ContainerBuilder $container): string
    {
        if (!$container->hasParameter('nowo_legal_kit.locales')) {
            return self::GENERIC_LOCALE_REQUIREMENT;
        }
        try {
            $raw     = $container->getParameterBag()->resolveValue($container->getParameter('nowo_legal_kit.locales'));
            $default = $container->getParameterBag()->resolveValue($container->getParameter('nowo_legal_kit.default_locale'));
            $locales = new LegalLocales($raw, is_string($default) ? $default : null);
        } catch (Throwable) {
            // Env var placeholder (resolved at runtime) or an invalid list: LegalLocales reports the latter at runtime.
            return self::GENERIC_LOCALE_REQUIREMENT;
        }

        return $locales->requirement();
    }
}
