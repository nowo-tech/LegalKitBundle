<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Forgets the placeholder scanner memo at every unit-of-work boundary, so the Twig extension, the admin and the
 * `check-placeholders` command never answer from hits computed before another worker edited a page, even when the
 * services resetter does not run (a host that removes or bypasses services_resetter, `messenger:consume --no-reset`,
 * long-running console processes):
 *
 * - HTTP: at the start of every main request (sub-requests keep the memo of their main request) and at
 *   kernel.terminate;
 * - Messenger consumers: when a message is received and after it is handled or failed;
 * - console: when a command starts and when it terminates.
 *
 * The Messenger and console events are subscribed by name, so symfony/messenger and symfony/console stay optional.
 * The scanner is injected as an iterator of IGNORE_ON_UNINITIALIZED_REFERENCE references: a boundary never builds it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 *
 * @internal
 */
final readonly class PlaceholderScannerResetSubscriber implements EventSubscriberInterface
{
    /**
     * @param iterable<ResetInterface> $resettables Already-built placeholder scanners
     */
    public function __construct(
        private iterable $resettables,
    ) {
    }

    /**
     * Request, message and command boundaries (event names as strings: Messenger and Console are optional).
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                              => ['onRequest', 4096],
            KernelEvents::TERMINATE                                            => ['reset', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent' => ['reset', 4096],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent'  => ['reset', -1024],
            'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent'   => ['reset', -1024],
            'console.command'                                                  => ['reset', 4096],
            'console.terminate'                                                => ['reset', -1024],
        ];
    }

    /**
     * Forgets the memo before a main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    /**
     * Forgets the memo of every already-built scanner.
     */
    public function reset(): void
    {
        foreach ($this->resettables as $resettable) {
            $resettable->reset();
        }
    }
}
