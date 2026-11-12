<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\Acceptance\AcceptanceRequestMatcher;
use Nowo\LegalKitBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * 1.13 (audit MU-L-I1): `acceptance.context_cookies` must not name an authentication cookie. The remember-me cookie of
 * every firewall (read from the security configuration, `REMEMBERME` by default) fails the build, like the generic
 * names refused by the configuration ({@see Configuration::REFUSED_CONTEXT_COOKIES}).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ContextCookiesPass implements CompilerPassInterface
{
    /**
     * Fails the build when a configured context cookie is a firewall's remember-me cookie.
     *
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AcceptanceRequestMatcher::class)) {
            return;
        }
        $arguments = $container->getDefinition(AcceptanceRequestMatcher::class)->getArguments();
        $listed    = $arguments['$contextCookies'] ?? [];
        if (!is_array($listed) || $listed === []) {
            return;
        }

        $authCookies = [];
        foreach ($container->findTaggedServiceIds('security.remember_me_handler') as $id => $tags) {
            $handler  = $container->getDefinition($id)->getArguments();
            $options  = $handler['index_3'] ?? $handler[3] ?? null;
            $name     = is_array($options) ? ($options['name'] ?? null) : null;
            $firewall = $tags[0]['firewall'] ?? null;
            if (!is_string($name) && is_string($firewall) && $container->hasDefinition('security.authenticator.remember_me.' . $firewall)) {
                $authenticator = $container->getDefinition('security.authenticator.remember_me.' . $firewall)->getArguments();
                $name          = $authenticator['index_2'] ?? $authenticator[2] ?? null;
            }
            $authCookies[] = is_string($name) && $name !== '' ? strtolower($name) : 'rememberme';
        }

        $refused = [];
        foreach ($listed as $cookie) {
            if (is_string($cookie) && in_array(strtolower($cookie), $authCookies, true)) {
                $refused[] = $cookie;
            }
        }
        if ($refused !== []) {
            throw new InvalidConfigurationException(sprintf('nowo_legal_kit.acceptance.context_cookies must not name an authentication cookie: "%s" is a firewall remember-me cookie.', implode('", "', $refused)));
        }
    }
}
