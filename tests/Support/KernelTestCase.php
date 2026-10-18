<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

        return $manager->getRepository(LegalDocument::class)->findOneBy(['slug' => $slug, 'locale' => $locale]);
    }
}
