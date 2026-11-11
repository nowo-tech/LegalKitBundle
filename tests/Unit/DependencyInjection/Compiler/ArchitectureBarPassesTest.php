<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\LegalKitBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\EntityManagerMappingPass;
use Nowo\LegalKitBundle\DependencyInjection\NowoLegalKitExtension;
use Nowo\LegalKitBundle\Doctrine\AfterCommitMiddleware;
use Nowo\LegalKitBundle\Doctrine\OwnEntityManagerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;
use function is_string;

final class ArchitectureBarPassesTest extends TestCase
{
    public function testTheEntitiesStayOnTheDedicatedManagerOnly(): void
    {
        $container = $this->container('legal', ['default', 'legal', 'empty']);
        $this->chain($container, 'default', ['App\Entity', EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::VERSIONING_NAMESPACE, EntityManagerMappingPass::ACCEPTANCE_NAMESPACE]);
        $this->chain($container, 'legal', [EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::ACCEPTANCE_NAMESPACE]);
        $container->getDefinition('doctrine.orm.default_metadata_driver')->addMethodCall('setDefaultDriver', [new Reference('x')]);

        $container->register(OwnEntityManagerSubscriber::class);
        (new EntityManagerMappingPass(true))->process($container);
        self::assertCount(5, $this->calls($container, 'default'), 'check only');
        self::assertFalse($container->getDefinition(OwnEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'));
        (new EntityManagerMappingPass())->process($container);
        self::assertTrue($container->getDefinition(OwnEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'), 'a manager the bundle owns');

        self::assertSame(['addDriver:App\Entity', 'setDefaultDriver:'], $this->calls($container, 'default'));
        self::assertSame(['addDriver:' . EntityManagerMappingPass::ENTITY_NAMESPACE, 'addDriver:' . EntityManagerMappingPass::ACCEPTANCE_NAMESPACE], $this->calls($container, 'legal'));
    }

    public function testNothingWithoutADedicatedManager(): void
    {
        $container = $this->container(null, ['default']);
        $this->chain($container, 'default', [EntityManagerMappingPass::ENTITY_NAMESPACE]);
        (new EntityManagerMappingPass())->process($container);
        self::assertCount(1, $this->calls($container, 'default'));

        $bare = new ContainerBuilder();
        (new EntityManagerMappingPass())->process($bare);
        $bare->setParameter(NowoLegalKitExtension::DEDICATED_MAPPING_PARAMETER, true);
        (new EntityManagerMappingPass())->process($bare);
        self::assertFalse($bare->hasParameter('doctrine.entity_managers'));
    }

    public function testTheDefaultManagerIsRefused(): void
    {
        $container = $this->container('default', ['default']);
        $this->chain($container, 'default', [EntityManagerMappingPass::ENTITY_NAMESPACE]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_legal_kit.doctrine.entity_manager is "default", your default Doctrine entity manager.');
        (new EntityManagerMappingPass(true))->process($container);
    }

    public function testAManagerThatAlsoMapsHostClassesIsRefused(): void
    {
        $container = $this->container('legal', ['default', 'legal']);
        $this->chain($container, 'legal', [EntityManagerMappingPass::ENTITY_NAMESPACE, 'App\Entity']);
        $container->getDefinition('doctrine.orm.legal_metadata_driver')->addMethodCall('setDefaultDriver', [new Reference('x')]);
        $container->register(OwnEntityManagerSubscriber::class);

        try {
            (new EntityManagerMappingPass())->process($container);
            self::fail('Expected the refusal.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame('The Doctrine entity manager "legal" (nowo_legal_kit.doctrine.entity_manager) also maps App\Entity, a default driver (every class). The bundle owns that manager (it resets and clears it between requests): give it a manager that maps only the bundle entities (auto_mapping: false, no other mappings).', $e->getMessage());
        }
        self::assertFalse($container->getDefinition(OwnEntityManagerSubscriber::class)->hasTag('kernel.event_subscriber'));
    }

    public function testAManagerThatDoesNotMapTheEntitiesFails(): void
    {
        $container = $this->container('legal', ['default', 'legal']);
        $this->chain($container, 'legal', ['App\Entity']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The Doctrine entity manager "legal" (nowo_legal_kit.doctrine.entity_manager) does not map Nowo\LegalKitBundle\Entity.');
        (new EntityManagerMappingPass())->process($container);
    }

    public function testTheMiddlewareFollowsTheBundleConnection(): void
    {
        $container = new ContainerBuilder();
        (new AfterCommitMiddlewarePass())->process($container);
        $container->register(AfterCommitMiddleware::class)->addTag('doctrine.middleware');
        $container->setParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER, 'legal');
        $container->register('doctrine.orm.legal_entity_manager')->setArguments([new Reference('doctrine.dbal.archive_connection')]);
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'archive']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));

        // No resolvable manager: the default connection.
        $container->setParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER, null);
        $container->setParameter('doctrine.default_connection', 'main');
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'main']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));

        $bare = new ContainerBuilder();
        $bare->register(AfterCommitMiddleware::class)->addTag('doctrine.middleware');
        (new AfterCommitMiddlewarePass())->process($bare);
        self::assertSame([['connection' => 'default']], $bare->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));
    }

    /**
     * @param list<string> $managers
     */
    private function container(?string $name, array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoLegalKitExtension::DEDICATED_MAPPING_PARAMETER, true);
        $container->setParameter(NowoLegalKitExtension::ENTITY_MANAGER_PARAMETER, $name);
        $map = [];
        foreach ($managers as $manager) {
            $map[$manager] = 'doctrine.orm.' . $manager . '_entity_manager';
        }
        $container->setParameter('doctrine.entity_managers', $map);
        $container->setParameter('doctrine.default_entity_manager', 'default');

        return $container;
    }

    /**
     * @param list<string> $namespaces
     */
    private function chain(ContainerBuilder $container, string $manager, array $namespaces): void
    {
        $chain = new Definition();
        foreach ($namespaces as $namespace) {
            $chain->addMethodCall('addDriver', [new Reference('driver'), $namespace]);
        }
        $container->setDefinition('doctrine.orm.' . $manager . '_metadata_driver', $chain);
    }

    /**
     * @return list<string>
     */
    private function calls(ContainerBuilder $container, string $manager): array
    {
        return array_values(array_map(static fn (array $call): string => $call[0] . ':' . (isset($call[1][1]) && is_string($call[1][1]) ? $call[1][1] : ''), $container->getDefinition('doctrine.orm.' . $manager . '_metadata_driver')->getMethodCalls()));
    }
}
