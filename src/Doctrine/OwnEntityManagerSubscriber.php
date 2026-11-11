<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the bundle's OWN entity manager (`doctrine.entity_manager`, 1.10) usable in a kept-alive kernel without the
 * services resetter.
 *
 * Registered only when `doctrine.entity_manager` is set: that manager maps the bundle entities only, so the bundle
 * resets it when a failed flush closed it (at the start of the next request, message or command) and clears it at
 * the end, so no managed legal entity survives into the next unit of work. The host's managers are never touched.
 *
 * @internal
 */
final readonly class OwnEntityManagerSubscriber implements EventSubscriberInterface
{
    /**
     * @param ManagerRegistry $registry Doctrine registry
     * @param string $managerName `doctrine.entity_manager`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $managerName,
    ) {
    }

    /**
     * Request, message and command boundaries (symfony/messenger is optional: event names as strings).
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                              => ['onRequest', 4096],
            ConsoleEvents::COMMAND                                             => ['recover', 4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent' => ['recover', 4096],
            KernelEvents::TERMINATE                                            => ['clear', -1024],
            ConsoleEvents::TERMINATE                                           => ['clear', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'  => ['clear', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'   => ['clear', -1024],
        ];
    }

    /**
     * Recovers the manager before a main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->recover();
        }
    }

    /**
     * Resets the bundle manager when a failed write closed it (the lazy manager is re-created in place, so the
     * services holding it keep working).
     */
    public function recover(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager($this->managerName);
        }
    }

    /**
     * Detaches every managed bundle entity at the end of the request, message or command (an open manager only).
     */
    public function clear(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && $manager->isOpen()) {
            // @igor-ignore - This IS the unit-of-work boundary reset of the bundle's own manager (no state kept).
            $manager->clear();
        }
    }
}
