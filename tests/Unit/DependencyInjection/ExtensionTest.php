<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManager;
use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Nowo\LegalKitBundle\Command\CheckPlaceholdersCommand;
use Nowo\LegalKitBundle\Command\DoctorCommand;
use Nowo\LegalKitBundle\Command\SeedCommand;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\IntegrationPass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\SecurityPass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Doctrine\AfterCommitEventDispatcher;
use Nowo\LegalKitBundle\Doctrine\AfterCommitMiddleware;
use Nowo\LegalKitBundle\Doctrine\OwnEntityManagerSubscriber;
use Nowo\LegalKitBundle\EventSubscriber\AdminAccessSubscriber;
use Nowo\LegalKitBundle\NowoLegalKitBundle;
use Nowo\LegalKitBundle\PersonalData\AcceptanceSubjectPersonalDataResolver;
use Nowo\LegalKitBundle\PersonalData\LegalAcceptancesPersonalDataEraser;
use Nowo\LegalKitBundle\PersonalData\LegalAcceptancesPersonalDataExportProvider;
use Nowo\LegalKitBundle\PersonalData\LegalPersonalDataSubjectResolverInterface;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Security\AllowAllLegalKitAccessChecker;
use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;
use Nowo\LegalKitBundle\Tests\Support\DependencyInjection\BrokenConfiguration;
use Nowo\LegalKitBundle\Tests\Support\DependencyInjection\FakeCookieConsentConfiguration;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Nowo\LegalKitBundle\Versioning\LegalPageVersionsInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

use function array_slice;

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
        self::assertSame(['legal_notice', 'legal_notice_unlocalized', 'legal_notice_bare'], array_slice($cookie['disabled_routes'], 0, 3));
        self::assertContains('legal_cookies_unlocalized', $cookie['disabled_routes'], 'Canonical pages are always there');
        self::assertNotContains('legal_privacy', $cookie['disabled_routes'], 'Disabled pages are left out');
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
        self::assertArrayHasKey('default', $container->getExtensionConfig('nowo_ckeditor5_editor')[0]['profiles']);
        $cookie = $container->getExtensionConfig('nowo_cookie_consent')[0];
        self::assertContains('legal_cookies_unlocalized', $cookie['disabled_routes']);
        self::assertArrayNotHasKey('render_routes', $cookie);
    }

    public function testADedicatedManagerIsMappedByTheBundleNotPrepended(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoLegalKitExtension());
        $container->registerExtension($this->fakeExtension('doctrine'));
        $container->loadFromExtension('nowo_legal_kit', ['doctrine' => ['entity_manager' => 'legal'], 'versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true]]);

        (new NowoLegalKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('doctrine'), 'Nothing prepended to the default manager');
        foreach ([NowoLegalKitExtension::DEDICATED_MAPPING_PARAMETER, NowoLegalKitExtension::DEDICATED_VERSIONING_MAPPING_PARAMETER, NowoLegalKitExtension::DEDICATED_ACCEPTANCE_MAPPING_PARAMETER] as $parameter) {
            self::assertTrue($container->getParameter($parameter));
        }
    }

    public function testAManagerTheMappingDidNotSeeFailsTheBuild(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        $container->registerExtension($this->fakeExtension('doctrine'));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_legal_kit.doctrine.entity_manager must be a literal manager name set in the host configuration');
        (new NowoLegalKitExtension())->load([['doctrine' => ['entity_manager' => 'legal', 'blame' => 'none']]], $container);
    }

    public function testTheDedicatedManagerIsWiredIntoTheBundleServices(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new NowoLegalKitExtension())->load([['doctrine' => ['entity_manager' => 'legal', 'blame' => 'none'], 'versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true, 'subject_resolver' => 'x']]], $container);

        self::assertSame('legal', $container->getParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER));
        foreach ([LegalPageVersions::class, LegalAcceptanceManager::class, SeedCommand::class, LegalDocumentPortability::class] as $service) {
            self::assertSame('doctrine.orm.legal_entity_manager', (string) $container->getDefinition($service)->getArgument('$entityManager'), $service);
        }
        self::assertSame(AfterCommitEventDispatcher::class, (string) $container->getDefinition(LegalAcceptanceManager::class)->getArgument('$dispatcher'));
        self::assertSame(LegalPageVersions::class, (string) $container->getAlias(LegalPageVersionsInterface::class));
        self::assertTrue($container->getDefinition(AfterCommitEventDispatcher::class)->hasTag('kernel.reset'));
        self::assertTrue($container->getDefinition(AfterCommitMiddleware::class)->hasTag('doctrine.middleware'));
        self::assertSame(['legal'], [$container->getDefinition(OwnEntityManagerSubscriber::class)->getArgument(1)]);
        self::assertFalse($container->getDefinition(OwnEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'), 'tagged by the mapping pass once the manager is checked');
    }

    public function testNoOwnManagerSubscriberWithoutTheOption(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new NowoLegalKitExtension())->load([[]], $container);

        self::assertFalse($container->hasDefinition(OwnEntityManagerSubscriber::class));
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
        $container = $this->withFormCsrf($this->loaded(['security' => ['access_checker' => 'app.checker']]));
        (new SecurityPass())->process($container);

        self::assertSame('app.checker', (string) $container->getAlias(LegalKitAccessCheckerInterface::class));
    }

    public function testSecurityPassRefusesTheAdminWithoutFormCsrf(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('form CSRF');
        (new SecurityPass())->process($this->loaded(['security' => ['access_checker' => 'app.checker']]));
    }

    public function testSecurityPassRefusesTheAdminWhenFormCsrfIsDisabled(): void
    {
        $container = $this->withFormCsrf($this->loaded(['security' => ['access_checker' => 'app.checker']]));
        $container->setParameter('form.type_extension.csrf.enabled', false);

        $this->expectException(InvalidConfigurationException::class);
        (new SecurityPass())->process($container);
    }

    private function withFormCsrf(ContainerBuilder $container): ContainerBuilder
    {
        $container->setDefinition('security.csrf.token_manager', new Definition(stdClass::class));
        $container->setDefinition('form.type_extension.csrf', new Definition(stdClass::class));

        return $container;
    }

    public function testSecurityPassRefusesAllowUnauthenticatedInProduction(): void
    {
        $container = $this->withFormCsrf($this->loaded(['security' => ['allow_unauthenticated' => true]]));
        $container->setParameter('kernel.environment', 'prod');
        $container->setParameter('kernel.debug', false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('dev/demo switch');
        (new SecurityPass())->process($container);
    }

    public function testSecurityPassKeepsAllowUnauthenticatedInDevAndTest(): void
    {
        foreach ([['dev', true], ['test', false]] as [$environment, $debug]) {
            $container = $this->withFormCsrf($this->loaded(['security' => ['allow_unauthenticated' => true]]));
            $container->setParameter('kernel.environment', $environment);
            $container->setParameter('kernel.debug', $debug);
            (new SecurityPass())->process($container);

            self::assertSame(AllowAllLegalKitAccessChecker::class, (string) $container->getAlias(LegalKitAccessCheckerInterface::class));
        }
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

    public function testSlugRequirement(): void
    {
        self::assertSame('notice|a\\.b', NowoLegalKitExtension::slugRequirement(['notice' => [], 'terms' => ['enabled' => false], 'a.b' => 'x']));
        self::assertSame('(?!)', NowoLegalKitExtension::slugRequirement(null));
    }

    public function testIntegrationPassLocaleRequirement(): void
    {
        $container = $this->loaded(['locales' => '%app.locales%', 'default_locale' => 'es']);
        $container->setParameter('app.locales', ['en', 'es']);
        (new IntegrationPass())->process($container);
        self::assertSame('es|en', $container->getParameter('nowo_legal_kit.locale_requirement'));

        $container = $this->loaded(['locales' => '%env(csv:APP_LOCALES)%']);
        (new IntegrationPass())->process($container);
        self::assertSame(IntegrationPass::GENERIC_LOCALE_REQUIREMENT, $container->getParameter('nowo_legal_kit.locale_requirement'));

        $container = new ContainerBuilder();
        (new IntegrationPass())->process($container);
        self::assertSame(IntegrationPass::GENERIC_LOCALE_REQUIREMENT, $container->getParameter('nowo_legal_kit.locale_requirement'));
    }

    public function testLegacyConsoleAliasesCanBeDropped(): void
    {
        $container = $this->loaded(['console' => ['legacy_aliases' => false]]);

        self::assertSame([['command' => 'nowo:legal-kit:check-placeholders']], $container->getDefinition(CheckPlaceholdersCommand::class)->getTag('console.command'));
        self::assertFalse($container->getDefinition(CheckPlaceholdersCommand::class)->isAutoconfigured());
    }

    public function testUserKitPersonalDataAdaptersWithAcceptance(): void
    {
        $container = $this->loaded(['versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true, 'subject_resolver' => 'app.subjects']]);

        self::assertTrue($container->getParameter('nowo_legal_kit.personal_data.enabled'));
        self::assertSame([['priority' => 150]], $container->getDefinition(LegalAcceptancesPersonalDataEraser::class)->getTag(NowoLegalKitExtension::USER_KIT_ERASER_TAG));
        self::assertSame([['priority' => 50]], $container->getDefinition(LegalAcceptancesPersonalDataExportProvider::class)->getTag(NowoLegalKitExtension::USER_KIT_EXPORT_PROVIDER_TAG));
        self::assertSame(AcceptanceSubjectPersonalDataResolver::class, (string) $container->getAlias(LegalPersonalDataSubjectResolverInterface::class));
        self::assertTrue($container->getAlias(LegalPersonalDataSubjectResolverInterface::class)->isPublic());
        self::assertSame('app.subjects', (string) $container->getDefinition(AcceptanceSubjectPersonalDataResolver::class)->getArgument(0));
        self::assertSame(LegalAcceptanceManagerInterface::class, (string) $container->getDefinition(LegalAcceptancesPersonalDataEraser::class)->getArgument(0));
        self::assertSame(LegalPersonalDataSubjectResolverInterface::class, (string) $container->getDefinition(LegalAcceptancesPersonalDataExportProvider::class)->getArgument(1));

        $custom = $this->loaded(['versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true, 'subject_resolver' => 'app.subjects'], 'personal_data' => ['subject_resolver' => 'app.personal_data_subjects']]);
        self::assertSame('app.personal_data_subjects', (string) $custom->getAlias(LegalPersonalDataSubjectResolverInterface::class));
    }

    public function testNoPersonalDataAdaptersWhenDisabledOrWithoutAcceptance(): void
    {
        $disabled = $this->loaded(['versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true, 'subject_resolver' => 'app.subjects'], 'personal_data' => ['enabled' => false]]);
        self::assertFalse($disabled->getParameter('nowo_legal_kit.personal_data.enabled'));
        foreach ([$disabled, $this->loaded([]), $this->loaded(['versioning' => ['enabled' => true]])] as $container) {
            self::assertFalse($container->hasDefinition(LegalAcceptancesPersonalDataEraser::class));
            self::assertFalse($container->hasDefinition(LegalAcceptancesPersonalDataExportProvider::class));
            self::assertFalse($container->hasAlias(LegalPersonalDataSubjectResolverInterface::class));
        }
    }

    public function testPrivacyRouteFallbackWithCookieConsent117(): void
    {
        self::assertSame('legal_privacy', $this->cookieConsentPrepend(FakeCookieConsentConfiguration::class)['privacy_route'] ?? null);
        self::assertArrayNotHasKey('privacy_route', $this->cookieConsentPrepend(BrokenConfiguration::class));
        self::assertArrayNotHasKey('privacy_route', $this->cookieConsentPrepend('App\\Missing\\Configuration'));
    }

    /**
     * @return array<string, mixed>
     */
    private function cookieConsentPrepend(string $configurationClass): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoLegalKitExtension($configurationClass));
        $container->registerExtension($this->fakeExtension('nowo_cookie_consent'));
        $container->loadFromExtension('nowo_legal_kit', []);
        (new NowoLegalKitExtension($configurationClass))->prepend($container);

        return $container->getExtensionConfig('nowo_cookie_consent')[0];
    }
}
