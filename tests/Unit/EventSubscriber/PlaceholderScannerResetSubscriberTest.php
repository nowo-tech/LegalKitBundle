<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Unit\EventSubscriber;

use Nowo\LegalKitBundle\EventSubscriber\PlaceholderScannerResetSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

final class PlaceholderScannerResetSubscriberTest extends TestCase
{
    public function testSubscribesToEveryUnitOfWorkBoundary(): void
    {
        self::assertSame(
            [
                KernelEvents::REQUEST                                              => ['onRequest', 4096],
                KernelEvents::TERMINATE                                            => ['reset', -1024],
                'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent' => ['reset', 4096],
                'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'  => ['reset', -1024],
                'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'   => ['reset', -1024],
                'console.command'                                                  => ['reset', 4096],
                'console.terminate'                                                => ['reset', -1024],
            ],
            PlaceholderScannerResetSubscriber::getSubscribedEvents(),
        );
    }

    public function testResetCallsEveryResettableDirectly(): void
    {
        $resettable = $this->createMock(ResetInterface::class);
        $resettable->expects(self::once())->method('reset');

        (new PlaceholderScannerResetSubscriber([$resettable]))->reset();
    }

    public function testOnlyMainRequestsReset(): void
    {
        $resettable = $this->createMock(ResetInterface::class);
        $resettable->expects(self::once())->method('reset');
        $subscriber = new PlaceholderScannerResetSubscriber([$resettable]);
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
    }
}
