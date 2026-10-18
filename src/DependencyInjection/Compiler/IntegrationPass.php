<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\EventListener\ActionLogListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ActionLogRecorder::class) && !$container->hasDefinition(ActionLogListener::class)) {
            $container->register(ActionLogListener::class, ActionLogListener::class)
                ->setArguments([new Reference(ActionLogRecorder::class)])
                ->addTag('kernel.event_subscriber');
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
}
