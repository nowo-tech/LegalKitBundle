<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\EventListener;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\LegalKitBundle\Event\LegalDocumentRestoredEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentSavedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;

/**
 * Records legal page edits in the AuditKit action log (registered when the host enables `nowo_audit_kit.action_log`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ActionLogListener implements EventSubscriberInterface
{
    public const string ACTION_SAVED = 'legal_document.saved';

    public const string ACTION_RESTORED = 'legal_document.restored';

    public const string ACTION_IMPORTED = 'legal_document.imported';

    /**
     * @param ActionLogRecorder $recorder AuditKit action log
     */
    public function __construct(
        private ActionLogRecorder $recorder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LegalDocumentSavedEvent::class     => 'onSaved',
            LegalDocumentRestoredEvent::class  => 'onRestored',
            LegalDocumentsImportedEvent::class => 'onImported',
        ];
    }

    /**
     * Logs a save.
     */
    public function onSaved(LegalDocumentSavedEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_SAVED, context: ['slug' => $event->slug, 'locale' => $event->locale]);
    }

    /**
     * Logs a restore.
     */
    public function onRestored(LegalDocumentRestoredEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_RESTORED, context: ['slug' => $event->slug, 'locale' => $event->locale]);
    }

    /**
     * Logs an import.
     */
    public function onImported(LegalDocumentsImportedEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_IMPORTED, context: ['count' => count($event->documents)]);
    }
}
