<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\EventListener\ActionLogListener;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Throwable;

use function is_array;
use function is_string;

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

    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ActionLogRecorder::class) && !$container->hasDefinition(ActionLogListener::class)) {
            $container->register(ActionLogListener::class, ActionLogListener::class)
                ->setArguments([new Reference(ActionLogRecorder::class)])
                // Registered only when the host enables the action log: tagged here (compiler-pass registration).
                ->addTag('kernel.event_subscriber');
        }

        $container->setParameter('nowo_legal_kit.locale_requirement', $this->localeRequirement($container));

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
