<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection;

use Nowo\LegalKitBundle\DependencyInjection\Compiler\IntegrationPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\SecurityPass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\EventSubscriber\AdminAccessSubscriber;
use Nowo\LegalKitBundle\NowoLegalKitBundle;
use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class ExtensionTest extends TestCase
{
    public function testBundleExposesTheExtension(): void
    {
        $bundle = new NowoLegalKitBundle();
        self::assertInstanceOf(NowoLegalKitExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
        self::assertSame('nowo_legal_kit', (new NowoLegalKitExtension())->getAlias());
    }

    public function testPrependRespectsTheHostConfiguration(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoLegalKitExtension());
        foreach (['framework', 'doctrine', 'nowo_form_kit', 'nowo_ckeditor5_editor', 'nowo_cookie_consent'] as $alias) {
            $container->registerExtension($this->fakeExtension($alias));
        }
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => ['legal_kit' => ['alias' => 'host']]]);
        $container->prependExtensionConfig('nowo_ckeditor5_editor', ['profiles' => ['legal' => ['preset' => 'minimal']]]);
        $container->loadFromExtension('nowo_legal_kit', ['pages' => ['notice' => [], 'privacy' => ['enabled' => false]]]);
        $container->loadFromExtension('nowo_cookie_consent', ['render_routes' => ['app_*']]);

        (new NowoLegalKitExtension())->prepend($container);

        self::assertSame([['profiles' => ['legal_kit' => ['alias' => 'host']]]], $container->getExtensionConfig('nowo_form_kit'));
        self::assertSame([['profiles' => ['legal' => ['preset' => 'minimal']]]], $container->getExtensionConfig('nowo_ckeditor5_editor'));
        $cookie = $container->getExtensionConfig('nowo_cookie_consent')[0];
        self::assertSame(['legal_notice', 'legal_notice_unlocalized', 'legal_notice_bare'], $cookie['disabled_routes']);
        self::assertSame(['legal_*'], $cookie['render_routes']);
        self::assertArrayNotHasKey('privacy_route', $cookie, 'No privacy page, no privacy_route fallback');
        self::assertArrayHasKey('mappings', $container->getExtensionConfig('doctrine')[0]['orm']);
        self::assertSame('/bundles/nowolegalkit', $container->getExtensionConfig('framework')[0]['assets']['packages']['nowo_legal_kit']['base_path']);
    }

    public function testPrependDefaultsAndMultipleEntityManagers(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoLegalKitExtension());
        foreach (['doctrine', 'nowo_form_kit', 'nowo_ckeditor5_editor', 'nowo_cookie_consent'] as $alias) {
            $container->registerExtension($this->fakeExtension($alias));
        }
        $container->loadFromExtension('doctrine', ['orm' => ['entity_managers' => ['default' => []]]]);
        $container->loadFromExtension('nowo_legal_kit', ['editor' => ['ckeditor5_profile' => 'policy']]);

        (new NowoLegalKitExtension())->prepend($container);

        self::assertCount(1, $container->getExtensionConfig('doctrine'), 'Hosts with several entity managers map the bundle themselves');
        self::assertSame('legal_kit', $container->getExtensionConfig('nowo_form_kit')[0]['profiles']['legal_kit']['alias']);
        self::assertArrayHasKey('policy', $container->getExtensionConfig('nowo_ckeditor5_editor')[0]['profiles']);
        $cookie = $container->getExtensionConfig('nowo_cookie_consent')[0];
        self::assertContains('legal_cookies_unlocalized', $cookie['disabled_routes']);
        self::assertArrayNotHasKey('render_routes', $cookie);
    }

    public function testCookieConsentPrependCanBeDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoLegalKitExtension());
        $container->registerExtension($this->fakeExtension('nowo_cookie_consent'));
        $container->loadFromExtension('nowo_legal_kit', ['cookie_consent' => ['prepend_routes' => false]]);

        (new NowoLegalKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_cookie_consent'));
    }

    public function testSecurityPassAliasesAHostChecker(): void
    {
        $container = $this->loaded(['security' => ['access_checker' => 'app.checker']]);
        (new SecurityPass())->process($container);

        self::assertSame('app.checker', (string) $container->getAlias(LegalKitAccessCheckerInterface::class));
    }

    public function testSecurityPassWithoutWebUiRemovesTheSubscriber(): void
    {
        $container = $this->loaded(['web_ui' => ['enabled' => false]]);
        (new SecurityPass())->process($container);

        self::assertFalse($container->hasDefinition(AdminAccessSubscriber::class));
        (new SecurityPass())->process(new ContainerBuilder());
    }

    public function testIntegrationPassReadsTheCookieConsentSettings(): void
    {
        $container = $this->loaded([]);
        $container->setParameter('nowo_cookie_consent.disabled_routes', ['legal_privacy', 42]);
        $container->setParameter('nowo_cookie_consent.privacy_route', 'legal_privacy');
        (new IntegrationPass())->process($container);

        $doctor = $container->getDefinition(DoctorCommand::class);
        self::assertSame(['legal_privacy'], $doctor->getArgument('$disabledRoutes'));
        self::assertSame('legal_privacy', $doctor->getArgument('$privacyRoute'));
        (new IntegrationPass())->process(new ContainerBuilder());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loaded(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new NowoLegalKitExtension())->load([$config], $container);

        return $container;
    }

    private function fakeExtension(string $alias): ExtensionInterface
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
