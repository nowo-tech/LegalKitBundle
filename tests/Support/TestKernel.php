<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Configuration as DoctrineConfiguration;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\AuditKitBundle\NowoAuditKitBundle;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\Ckeditor5EditorBundle\NowoCkeditor5EditorBundle;
use Nowo\CookieConsentBundle\NowoCookieConsentBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\LegalKitBundle\Blame\LegalActorIdResolverInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolverInterface;
use Nowo\LegalKitBundle\NowoLegalKitBundle;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortabilityInterface;
use Nowo\LegalKitBundle\Security\AllowAllLegalKitAccessChecker;
use Nowo\LegalKitBundle\Seed\LegalSeedProviderInterface;
use Nowo\LegalKitBundle\Tests\Support\Entity\User;
use Nowo\LegalKitBundle\Tests\Support\Seed\TestSeedProvider;
use Nowo\OutboundUrlGuardBundle\NowoOutboundUrlGuardBundle;
use Nowo\SeoKitBundle\SeoKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getenv;
use function getmypid;
use function hash;
use function in_array;
use function is_string;
use function serialize;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Real application kernel for integration / functional tests: SQLite in memory, AuditKit with the test User, UiKit,
 * FormKit and the bundle; optional integrations switched on per test (`$extraBundles`: ckeditor, cookie_consent,
 * seo_kit, outbound_url_guard). One cache directory per configuration and PHPUnit process.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * Options of `$extraBundles` that are not bundles: Twig consent stub, host seed provider, host brand provider
     * (`brand_provider`, aliased as the brand-contracts `BrandProviderInterface`), no route import, only the
     * host's default `routing.controllers` import (Symfony >= 7.3 skeleton routes.yaml, no bundle route import), a
     * host actor id resolver (`actor_resolver`, aliased as LegalActorIdResolverInterface), host decorators of the
     * document resolver and portability ports (`host_documents`), UserKit's personal-data eraser and exporter services
     * fed by the tagged adapters (`user_kit`, without the UserKit bundle itself), a host subject SPI
     * (`subject_spi`, service `test.legal_kit.personal_data_subjects`), and the MySQL / MariaDB / PostgreSQL server of
     * LEGAL_KIT_TEST_DATABASE_URL (`server_database`, CI job "Subject ids on database servers"; SQLite in memory when
     * the variable is not set), and the 1.12 framework-free twin ports (`ddd_ports`: subject id resolver service
     * `test.legal_kit.subject_id_resolver` and a request exemption voter).
     */
    public const array PSEUDO_OPTIONS = ['consent_stub', 'test_seed', 'no_routes', 'host_controllers', 'app_routes', 'brand_provider', 'actor_resolver', 'host_documents', 'user_kit', 'subject_spi', 'server_database', 'ddd_ports'];

    /** Environment variable of the database server (`server_database`). */
    public const string DATABASE_URL_ENV = 'LEGAL_KIT_TEST_DATABASE_URL';

    private readonly string $cacheKey;

    /**
     * @param array<string, mixed> $legalKitConfig nowo_legal_kit configuration
     * @param list<string> $extraBundles Optional integrations to register
     * @param bool $withSecurity Register SecurityBundle (in-memory users, form login-less http_basic)
     * @param array<string, array<string, mixed>> $extensionConfigs Extra extension configs (alias => config)
     */
    public function __construct(
        private readonly array $legalKitConfig = [],
        private readonly array $extraBundles = [],
        private readonly bool $withSecurity = true,
        private readonly array $extensionConfigs = [],
    ) {
        $this->cacheKey = hash('xxh128', serialize([$legalKitConfig, $extraBundles, $withSecurity, $extensionConfigs]));
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        if ($this->withSecurity) {
            yield new SecurityBundle();
        }
        yield new NowoAuditKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        foreach ($this->extraBundles as $extra) {
            if (!in_array($extra, self::PSEUDO_OPTIONS, true)) {
                yield $this->extraBundle($extra);
            }
        }
        yield new NowoLegalKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-legal-kit-bundle/' . getmypid() . '/' . $this->cacheKey . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-legal-kit-bundle/' . getmypid() . '/' . $this->cacheKey . '/log';
    }

    /**
     * Creates the schema of every mapped entity in the in-memory database.
     */
    public static function createSchema(EntityManagerInterface $entityManager): void
    {
        $tool     = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        if (!$entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            // A database server keeps the tables of the previous test.
            $tool->dropSchema($metadata);
        }
        $tool->createSchema($metadata);
    }

    /**
     * The database server of {@see DATABASE_URL_ENV}, or null (SQLite in memory).
     */
    public static function serverDatabaseUrl(): ?string
    {
        $url = getenv(self::DATABASE_URL_ENV);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Symfony 7.4 registers the `clock` service only when symfony/clock is a root requirement: provide it otherwise
     * (AuditKit autowires the PSR-20 clock; Flex applications have symfony/clock).
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if (!$container->has('clock')) {
                    $container->register('clock', NativeClock::class);
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret'               => 'test',
            'test'                 => true,
            'http_method_override' => false,
            'router'               => ['utf8' => true],
            'csrf_protection'      => true,
            'form'                 => ['enabled' => true],
            'session'              => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'default_locale'       => 'en',
            'translator'           => ['fallbacks' => ['en']],
            'property_access'      => true,
            'validation'           => true,
        ]);
        $container->loadFromExtension('twig', ['strict_variables' => true]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => in_array('server_database', $this->extraBundles, true) ? (self::serverDatabaseUrl() ?? 'sqlite:///:memory:') : 'sqlite:///:memory:'],
            'orm'  => $this->ormConfig() + ['mappings' => ['LegalKitTests' => [
                'type'      => 'attribute',
                'is_bundle' => false,
                'dir'       => __DIR__ . '/Entity',
                'prefix'    => 'Nowo\\LegalKitBundle\\Tests\\Support\\Entity',
            ]]],
        ]);
        $container->loadFromExtension('nowo_audit_kit', ['user_class' => User::class]);
        if ($this->withSecurity) {
            $container->loadFromExtension('security', [
                'password_hashers' => [User::class => 'plaintext', InMemoryUser::class => 'plaintext'],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                    'editor' => ['password' => 'editor', 'roles' => ['ROLE_USER']],
                    'robot'  => ['password' => 'robot', 'roles' => ['ROLE_USER']],
                    'bad'    => ['password' => 'bad', 'roles' => ['ROLE_USER']],
                    'boom'   => ['password' => 'boom', 'roles' => ['ROLE_USER']],
                ]]]],
                'firewalls' => ['main' => ['lazy' => true, 'http_basic' => null, 'provider' => 'memory']],
            ]);
        }
        // AuditKit autowires the PSR-20 clock (a Flex host aliases it through symfony/clock).
        $container->setAlias(ClockInterface::class, 'clock');
        // Acceptance host services (acceptance.subject_resolver: test.legal_kit.subject_resolver) and a voter.
        $container->register('test.legal_kit.subject_resolver', TestSubjectResolver::class);
        $container->register(TestExemption::class, TestExemption::class)->setAutoconfigured(true);
        $container->register('test.legal_kit.deny_publish', DenyPublisherChecker::class);
        $container->register(AppController::class, AppController::class)->setPublic(true)->addTag('controller.service_arguments');
        // A host access checker (security.access_checker: test.legal_kit.allow_all).
        $container->register('test.legal_kit.allow_all', AllowAllLegalKitAccessChecker::class);
        if (in_array('test_seed', $this->extraBundles, true)) {
            $container->register(TestSeedProvider::class, TestSeedProvider::class)->addTag(LegalSeedProviderInterface::TAG, ['priority' => 10]);
        }
        if (in_array('brand_provider', $this->extraBundles, true)) {
            $container->register(TestBrandProvider::class, TestBrandProvider::class)->setPublic(true);
            $container->setAlias(BrandProviderInterface::class, TestBrandProvider::class);
        }
        if (in_array('actor_resolver', $this->extraBundles, true)) {
            $container->register(TestActorIdResolver::class, TestActorIdResolver::class)->setAutowired(true);
            $container->setAlias(LegalActorIdResolverInterface::class, TestActorIdResolver::class);
        }
        if (in_array('host_documents', $this->extraBundles, true)) {
            $container->register(TestHostDocumentResolver::class, TestHostDocumentResolver::class)->setAutowired(true);
            $container->setAlias(LegalDocumentResolverInterface::class, TestHostDocumentResolver::class);
            $container->register(TestHostPortability::class, TestHostPortability::class)->setAutowired(true)->setPublic(true);
            $container->setAlias(LegalDocumentPortabilityInterface::class, TestHostPortability::class)->setPublic(true);
        }
        if (in_array('user_kit', $this->extraBundles, true)) {
            $container->register(PersonalDataEraser::class, PersonalDataEraser::class)
                ->setArguments([new TaggedIteratorArgument('nowo_user_kit.personal_data.eraser'), [], new Reference('doctrine')])
                ->setPublic(true);
            $container->register(PersonalDataExporter::class, PersonalDataExporter::class)
                ->setArguments([new TaggedIteratorArgument('nowo_user_kit.personal_data.export_provider'), new Reference('clock')])
                ->setPublic(true);
        }
        if (in_array('ddd_ports', $this->extraBundles, true)) {
            // 1.12 framework-free twins: subject id resolver (acceptance.subject_resolver: test.legal_kit.subject_id_resolver)
            // and an autoconfigured request exemption voter.
            $container->register('test.legal_kit.subject_id_resolver', TestSubjectIdResolver::class);
            $container->register(TestRequestExemption::class, TestRequestExemption::class)->setAutoconfigured(true);
        }
        if (in_array('subject_spi', $this->extraBundles, true)) {
            $container->register('test.legal_kit.personal_data_subjects', TestPersonalDataSubjectResolver::class);
        }
        if (in_array('consent_stub', $this->extraBundles, true)) {
            $container->register(ConsentStubTwigExtension::class, ConsentStubTwigExtension::class)->addTag('twig.extension');
        }
        foreach ($this->extensionConfigs as $alias => $config) {
            $container->loadFromExtension($alias, $config);
        }
        $container->loadFromExtension('nowo_legal_kit', $this->legalKitConfig + ['locales' => ['en', 'es', 'de']]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if (in_array('no_routes', $this->extraBundles, true)) {
            return;
        }
        if (in_array('host_controllers', $this->extraBundles, true)) {
            $routes->import('routing.controllers');

            return;
        }
        $routes->import('.', 'nowo_legal_kit');
        if (in_array('app_routes', $this->extraBundles, true)) {
            foreach (['app_home' => '/app', 'app_mfa' => '/app/mfa', 'app_skip' => '/app/skip', 'api_skip' => '/api/skip', 'app_logout' => '/app/logout', 'app_voter_boom' => '/app/voter-boom'] as $name => $path) {
                $routes->add($name, $path)->controller([AppController::class, 'page']);
            }
            $routes->add('app_data', '/app/data')->controller([AppController::class, 'data']);
        }
    }

    private function extraBundle(string $name): BundleInterface
    {
        return match ($name) {
            'ckeditor'           => new NowoCkeditor5EditorBundle(),
            'cookie_consent'     => new NowoCookieConsentBundle(),
            'seo_kit'            => new SeoKitBundle(),
            'outbound_url_guard' => new NowoOutboundUrlGuardBundle(),
            default              => throw new InvalidArgumentException('Unknown test bundle ' . $name),
        };
    }

    /**
     * ORM 3 on PHP 8.4+: native lazy objects (DoctrineBundle 2.x needs the option, 3.x deprecates it).
     *
     * @return array<string, mixed>
     */
    private function ormConfig(): array
    {
        if (PHP_VERSION_ID < 80400) {
            return [];
        }
        $root = (new DoctrineConfiguration(false))->getConfigTreeBuilder()->buildTree();
        $orm  = $root instanceof ArrayNode ? ($root->getChildren()['orm'] ?? null) : null;
        $node = $orm instanceof ArrayNode ? ($orm->getChildren()['enable_native_lazy_objects'] ?? null) : null;

        return $node instanceof BaseNode && !$node->isDeprecated() ? ['enable_native_lazy_objects' => true] : [];
    }
}
