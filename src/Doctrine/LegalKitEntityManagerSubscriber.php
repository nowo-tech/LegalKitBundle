<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit-of-work boundaries of the bundle's PRIVATE entity manager ({@see LegalKitEntityManager}, 1.12): registered when
 * `doctrine.entity_manager` is not set. Works without the services resetter (FrankenPHP worker with kernel reset off,
 * `messenger:consume --no-reset`, long-running console processes):
 *
 * - at the start of a main request, a received message and a command: a manager that a failed write closed is dropped
 *   (the next call creates a fresh one) and the open one is cleared;
 * - at kernel.terminate, after a message is handled or failed, and at console.terminate: the manager is cleared, so
 *   no managed legal entity survives into the next unit of work.
 *
 * Never creates the private manager and never touches an application manager. The Messenger and console events are
 * subscribed by name (both components optional).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalKitEntityManagerSubscriber implements EventSubscriberInterface
{
    /**
     * @param LegalKitEntityManager $entityManager The bundle's private manager
     */
    public function __construct(
        private LegalKitEntityManager $entityManager,
    ) {
    }

    /**
     * Request, message and command boundaries.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                              => ['onRequest', 4096],
            'console.command'                                                  => ['begin', 4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent' => ['begin', 4096],
            KernelEvents::TERMINATE                                            => ['end', -1024],
            'console.terminate'                                                => ['end', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'  => ['end', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'   => ['end', -1024],
        ];
    }

    /**
     * Starts a unit of work at a main request (sub-requests share their main request's unit of work).
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->begin();
        }
    }

    /**
     * Start of a unit of work: drops a closed manager, clears an open one.
     */
    public function begin(): void
    {
        // @igor-ignore - Unit-of-work boundary of the bundle's own private manager (no state kept).
        $this->entityManager->recover();
        // @igor-ignore - Unit-of-work boundary of the bundle's own private manager (no state kept).
        $this->entityManager->clear();
    }

    /**
     * End of a unit of work: detaches every legal entity.
     */
    public function end(): void
    {
        // @igor-ignore - Unit-of-work boundary of the bundle's own private manager (no state kept).
        $this->entityManager->clear();
    }
}
