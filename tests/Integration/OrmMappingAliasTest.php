<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Tests\Support\VersioningTestCase;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function array_values;
use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * DoctrineBundle 3.4 deprecates the ORM mapping `alias` option (removed in 4.0): the mappings the bundle prepends must
 * not set it, a kernel boots without that deprecation and the entities are still mapped.
 */
final class OrmMappingAliasTest extends VersioningTestCase
{
    public function testThePrependedMappingsSetNoAlias(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $container->prependExtensionConfig('nowo_legal_kit', ['versioning' => ['enabled' => true], 'acceptance' => ['enabled' => true]]);
        (new NowoLegalKitExtension())->prepend($container);

        $mappings = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            foreach ($config['orm']['mappings'] ?? [] as $name => $mapping) {
                $mappings[$name] = $mapping;
            }
        }

        self::assertArrayHasKey('NowoLegalKitBundle', $mappings);
        self::assertArrayHasKey('NowoLegalKitVersioning', $mappings);
        self::assertArrayHasKey('NowoLegalKitAcceptance', $mappings);
        foreach ($mappings as $name => $mapping) {
            self::assertArrayNotHasKey('alias', $mapping, $name);
        }
    }

    public function testBootingRaisesNoAliasDeprecationAndStillMapsTheEntities(): void
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper, restored in the finally block)
        set_error_handler(static function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $kernel = $this->acceptanceKernel();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'alias option'))));
        $metadata = $this->entityManager($kernel)->getMetadataFactory();
        foreach ([LegalDocument::class, LegalPageVersion::class, LegalAcceptance::class] as $class) {
            self::assertTrue($metadata->hasMetadataFor($class), $class);
        }
    }
}
