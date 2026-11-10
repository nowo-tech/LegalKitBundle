<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Nowo\LegalKitBundle\Document\LegalDocumentResolver;
use Nowo\LegalKitBundle\Document\LegalDocumentResolverInterface;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortabilityInterface;
use Nowo\LegalKitBundle\Tests\Support\KernelTestCase;
use Nowo\LegalKitBundle\Tests\Support\TestHostPortability;
use Nowo\LegalKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function is_string;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * DDD / hexagonal ports (1.9): `LegalDocumentResolverInterface` and `LegalDocumentPortabilityInterface`, aliased to
 * the final classes by default; a host alias replaces them for the bundle's own consumers too.
 */
final class DocumentPortsTest extends KernelTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    public function testTheInterfacesAreAliasedToTheBundleClassesByDefault(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->testContainer($kernel);

        self::assertSame($container->get(LegalDocumentResolver::class), $container->get(LegalDocumentResolverInterface::class));
        self::assertSame($container->get(LegalDocumentPortability::class), $container->get(LegalDocumentPortabilityInterface::class));
        self::assertTrue($kernel->getContainer()->has(LegalDocumentPortabilityInterface::class), 'Public like the class');

        $resolver = $container->get(LegalDocumentResolverInterface::class);
        self::assertInstanceOf(LegalDocumentResolver::class, $resolver);
        self::assertNotNull($resolver->resolve('privacy', 'en'));
    }

    public function testDefaultPublicPageAndImportAreUnchanged(): void
    {
        $kernel = $this->bootKernel();
        $this->store($kernel, 'privacy', 'en', 'Our privacy', '<p>Data</p>');
        $client = $this->client($kernel);
        $client->request('GET', '/legal/privacy');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Our privacy', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Host: ', (string) $client->getResponse()->getContent());

        self::assertSame(Command::SUCCESS, $this->import($kernel));
        self::assertSame('Terms', $this->find($kernel, 'terms', 'en')?->getTitle());
    }

    public function testAHostImplementationServesTheBundlesOwnConsumers(): void
    {
        $kernel = $this->bootKernel([], ['host_documents']);
        $this->store($kernel, 'privacy', 'en', 'Our privacy', '<p>Data</p>');
        $client = $this->client($kernel);
        $client->request('GET', '/legal/privacy');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Host: Our privacy', (string) $client->getResponse()->getContent(), 'The public page uses the interface');

        self::assertSame(Command::SUCCESS, $this->import($kernel));
        self::assertSame('Terms', $this->find($kernel, 'terms', 'en')?->getTitle());
        $host = $this->testContainer($kernel)->get(TestHostPortability::class);
        self::assertInstanceOf(TestHostPortability::class, $host);
        self::assertSame(1, $host->applied, 'The import command uses the interface');
    }

    private function import(TestKernel $kernel): int
    {
        $file = tempnam(sys_get_temp_dir(), 'legal-import');
        self::assertTrue(is_string($file));
        $this->files[] = $file;
        file_put_contents($file, (string) json_encode([['slug' => 'terms', 'locale' => 'en', 'title' => 'Terms', 'body' => '<p>T</p>']]));

        return (new CommandTester((new Application($kernel))->find('nowo:legal-kit:import')))->execute(['file' => $file]);
    }
}
