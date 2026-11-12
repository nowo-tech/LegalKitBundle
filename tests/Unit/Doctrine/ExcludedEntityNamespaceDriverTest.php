<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\DependencyInjection\Compiler\EntityManagerMappingPass;
use Nowo\LegalKitBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(LegalDocument::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(LegalDocument::class);
        $driver->loadMetadataForClass(LegalDocument::class, new ClassMetadata(LegalDocument::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the bundle entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(LegalDocument::class));

        foreach ([EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::VERSIONING_NAMESPACE, EntityManagerMappingPass::ACCEPTANCE_NAMESPACE] as $namespace) {
            $chain->addDriver(new ExcludedEntityNamespaceDriver(), $namespace);
        }

        foreach ([LegalDocument::class, LegalPageVersion::class, LegalAcceptance::class] as $class) {
            self::assertTrue($chain->isTransient($class), $class);
        }
        self::assertFalse($chain->isTransient(self::class), 'other classes still go to the default driver');
    }
}
