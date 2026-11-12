<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\Acceptance\AcceptanceRequestMatcher;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\ContextCookiesPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * 1.13 (audit MU-L-I1): `acceptance.context_cookies` never names a firewall's remember-me cookie.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ContextCookiesPassTest extends TestCase
{
    /**
     * @param list<string> $cookies
     */
    private function container(array $cookies): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AcceptanceRequestMatcher::class, (new Definition(AcceptanceRequestMatcher::class))->setArgument('$contextCookies', $cookies));
        // a built-in handler with its options (argument 3)
        $container->setDefinition('security.authenticator.remember_me_handler.main', (new Definition('stdClass', [null, null, null, ['name' => 'APP_REMEMBER']]))
            ->addTag('security.remember_me_handler', ['firewall' => 'main']));
        // a custom handler: the name comes from the firewall's remember-me authenticator (argument 2)
        $container->setDefinition('security.authenticator.remember_me_handler.api', (new Definition('stdClass'))->addTag('security.remember_me_handler', ['firewall' => 'api']));
        $container->setDefinition('security.authenticator.remember_me.api', new Definition('stdClass', [null, null, 'API_REMEMBER']));
        // a handler whose name cannot be read: the default REMEMBERME
        $container->setDefinition('security.authenticator.remember_me_handler.admin', (new Definition('stdClass'))->addTag('security.remember_me_handler', ['firewall' => 'admin']));

        return $container;
    }

    public function testOrdinaryCookiesPass(): void
    {
        (new ContextCookiesPass())->process($this->container(['e2e_gate', 'theme']));
        (new ContextCookiesPass())->process($this->container([]));
        (new ContextCookiesPass())->process(new ContainerBuilder());
        $this->addToAssertionCount(3);
    }

    public function testAFirewallRememberMeCookieFailsTheBuild(): void
    {
        foreach (['app_remember', 'API_REMEMBER', 'RememberMe'] as $name) {
            try {
                (new ContextCookiesPass())->process($this->container(['e2e_gate', $name]));
                self::fail($name . ' was accepted');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('"' . $name . '"', $exception->getMessage());
            }
        }
    }
}
