<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\LegalKitBundle\Doctrine\OwnEntityManagerSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class OwnEntityManagerSubscriberTest extends TestCase
{
    public function testBoundaries(): void
    {
        $events = OwnEntityManagerSubscriber::getSubscribedEvents();
        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        self::assertSame(['recover', 4096], $events[ConsoleEvents::COMMAND]);
        self::assertSame(['clear', -1024], $events[KernelEvents::TERMINATE]);
        self::assertArrayHasKey('Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', $events);
    }

    public function testAClosedManagerIsResetOnlyOnAMainRequest(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $manager->expects(self::never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('legal')->willReturn($manager);
        $registry->expects(self::once())->method('resetManager')->with('legal');
        $subscriber = new OwnEntityManagerSubscriber($registry, 'legal');
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->clear();
    }

    public function testAnOpenManagerIsClearedNotReset(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects(self::once())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects(self::never())->method('resetManager');
        $subscriber = new OwnEntityManagerSubscriber($registry, 'legal');

        $subscriber->recover();
        $subscriber->clear();
    }

    public function testANonOrmManagerIsLeftAlone(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects(self::never())->method('resetManager');
        $subscriber = new OwnEntityManagerSubscriber($registry, 'legal');

        $subscriber->recover();
        $subscriber->clear();
    }
}
