<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use Nowo\LegalKitBundle\EventSubscriber\AdminAccessSubscriber;
use Nowo\LegalKitBundle\Security\AllowAllLegalKitAccessChecker;
use Nowo\LegalKitBundle\Security\ConfigurableLegalKitAccessChecker;
use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;

/**
 * Wires the admin access checker (REQ-UI-002): a host `security.access_checker`, the role checker, or — only with
 * `allow_unauthenticated` — the allow-all checker. Fails the build when the admin UI is enabled without
 * SecurityBundle and without `allow_unauthenticated`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SecurityPass implements CompilerPassInterface
{
    /**
     * Removes the admin access subscriber when the web UI is disabled; otherwise picks the access checker (custom, allow-all or role-based, which needs SecurityBundle).
     */
    public function process(ContainerBuilder $container): void
    {
        $prefix = Configuration::ALIAS . '.';
        if (!$container->hasParameter($prefix . 'web_ui.enabled') || !(bool) $container->getParameter($prefix . 'web_ui.enabled')) {
            $container->removeDefinition(AdminAccessSubscriber::class);

            return;
        }

        $allowUnauthenticated = (bool) $container->getParameter($prefix . 'security.allow_unauthenticated');
        $customChecker        = $container->getParameter($prefix . 'security.access_checker');

        if (is_string($customChecker) && $customChecker !== '') {
            $container->setAlias(LegalKitAccessCheckerInterface::class, $customChecker);

            return;
        }
        if ($allowUnauthenticated) {
            $container->setAlias(LegalKitAccessCheckerInterface::class, AllowAllLegalKitAccessChecker::class);

            return;
        }
        if (!$container->has('security.authorization_checker')) {
            throw new InvalidConfigurationException('nowo_legal_kit admin UI requires symfony/security-bundle (security.authorization_checker), or set nowo_legal_kit.security.allow_unauthenticated: true (dev/demo only — never in production), or web_ui.enabled: false.');
        }

        $container->register(ConfigurableLegalKitAccessChecker::class, ConfigurableLegalKitAccessChecker::class)
            ->setArguments([new Reference('security.authorization_checker'), '%' . $prefix . 'security.access_roles%']);
        $container->setAlias(LegalKitAccessCheckerInterface::class, ConfigurableLegalKitAccessChecker::class);
    }
}
