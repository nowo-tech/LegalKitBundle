<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Doctrine\LegalKitEntityManager;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Repository\LegalDocumentRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use function count;
use function restore_exception_handler;

/**
 * Boots a {@see TestKernel} with a fresh in-memory schema; helpers for HTTP clients and stored documents.
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
            restore_exception_handler();
        }
        $this->kernels = [];
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config nowo_legal_kit configuration
     * @param list<string> $extraBundles Optional integrations
     * @param array<string, array<string, mixed>> $extensionConfigs Extra extension configs
     */
    protected function bootKernel(array $config = [], array $extraBundles = [], bool $withSecurity = true, array $extensionConfigs = []): TestKernel
    {
        $kernel = new TestKernel($config, $extraBundles, $withSecurity, $extensionConfigs);
        $kernel->boot();
        $this->kernels[] = $kernel;
        TestKernel::createSchema($this->entityManager($kernel));

        return $kernel;
    }

    /**
     * A second booted kernel of the same configuration on the same database (no schema change): two connections.
     */
    protected function twinKernel(TestKernel $kernel): TestKernel
    {
        $twin = $kernel->twin();
        $twin->boot();
        $this->kernels[] = $twin;

        return $twin;
    }

    protected function client(TestKernel $kernel): KernelBrowser
    {
        $client = new KernelBrowser($kernel);
        $client->disableReboot();

        return $client;
    }

    protected function adminClient(TestKernel $kernel, string $user = 'admin'): KernelBrowser
    {
        $client = $this->client($kernel);
        $client->setServerParameters(['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
    }

    /**
     * The test container (private services included).
     */
    protected function testContainer(TestKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    protected function entityManager(TestKernel $kernel): EntityManagerInterface
    {
        $manager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * Stores a changed legal document the way the bundle does (its repository: the bundle's private manager since 1.12,
     * which a flush of the application's manager never writes).
     */
    protected function save(TestKernel $kernel, LegalDocument $document): void
    {
        $repository = $this->testContainer($kernel)->get(LegalDocumentRepository::class);
        self::assertInstanceOf(LegalDocumentRepository::class, $repository);
        $repository->save($document);
    }

    /**
     * Detaches every row from the bundle's private manager (1.12), as the unit-of-work boundaries do.
     */
    protected function clearBundleManager(TestKernel $kernel): void
    {
        $container = $this->testContainer($kernel);
        if ($container->has(LegalKitEntityManager::class)) {
            $private = $container->get(LegalKitEntityManager::class);
            self::assertInstanceOf(LegalKitEntityManager::class, $private);
            $private->clear();
        }
    }

    /**
     * Records, when the response of the next request is built (before the terminate boundary clears the private
     * manager), how many managed rows of the bundle's private manager have a pending change.
     *
     * It also keeps the inner manager the request starts with (`inner`): `live()` replaces a closed inner manager, so an
     * `isOpen()` on the decorator is always true; the retained instance tells whether the request closed it.
     */
    protected function watchDirtyRowsAtResponse(TestKernel $kernel): ResponseProbe
    {
        $seen    = new ResponseProbe();
        $private = $this->testContainer($kernel)->get(LegalKitEntityManager::class);
        self::assertInstanceOf(LegalKitEntityManager::class, $private);
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::REQUEST, static function () use ($private, $seen): void {
            $seen->inner ??= $private->live();
        }, -1000);
        $dispatcher->addListener(KernelEvents::RESPONSE, static function () use ($private, $seen): void {
            $unitOfWork = $private->live()->getUnitOfWork();
            $unitOfWork->computeChangeSets();
            $seen->dirty = count($unitOfWork->getScheduledEntityUpdates());
        });

        return $seen;
    }

    protected function store(TestKernel $kernel, string $slug, string $locale, string $title, string $body): LegalDocument
    {
        $document = new LegalDocument($slug, $locale);
        $document->setTitle($title);
        $document->setBody($body);
        $manager = $this->entityManager($kernel);
        $manager->persist($document);
        $manager->flush();
        $manager->clear();

        return $document;
    }

    protected function find(TestKernel $kernel, string $slug, string $locale): ?LegalDocument
    {
        $manager = $this->entityManager($kernel);
        $manager->clear();
        $this->clearBundleManager($kernel);

        return $manager->getRepository(LegalDocument::class)->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }
}
