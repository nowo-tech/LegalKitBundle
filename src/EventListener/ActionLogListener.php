<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\EventListener;

use Nowo\AuditKitBundle\ActionLog\ActionLogRecorder;
use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentRebasedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentRestoredEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentSavedEvent;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;
use Nowo\LegalKitBundle\Event\LegalSeedUpgradedEvent;
use Nowo\LegalKitBundle\Event\LegalVersionPublishedEvent;
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

    public const string ACTION_VERSION_PUBLISHED = 'legal_document.version_published';

    public const string ACTION_REBASED = 'legal_document.rebased';

    public const string ACTION_SEED_UPGRADED = 'legal_document.seed_upgraded';

    public const string ACTION_ACCEPTED = 'legal_document.accepted';

    /**
     * @param ActionLogRecorder $recorder AuditKit action log
     */
    public function __construct(
        private ActionLogRecorder $recorder,
        private bool $logAcceptances = false,
    ) {
    }

    /**
     * Records saved, restored and imported legal documents in the action log.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LegalDocumentSavedEvent::class     => 'onSaved',
            LegalDocumentRestoredEvent::class  => 'onRestored',
            LegalDocumentsImportedEvent::class => 'onImported',
            LegalVersionPublishedEvent::class  => 'onVersionPublished',
            LegalDocumentRebasedEvent::class   => 'onRebased',
            LegalSeedUpgradedEvent::class      => 'onSeedUpgraded',
            LegalAcceptedEvent::class          => 'onAccepted',
        ];
    }

    /**
     * Logs a save.
     *
     * @return void Nothing (side effects only)
     */
    public function onSaved(LegalDocumentSavedEvent $event): void
    {
        $context = ['slug' => $event->slug, 'locale' => $event->locale];
        if ($event->publication !== null) {
            $context['publication'] = $event->publication;
            $context['version']     = $event->version;
        }
        $this->recorder->recordAndFlush(self::ACTION_SAVED, context: $context);
    }

    /**
     * Logs a restore.
     *
     * @return void Nothing (side effects only)
     */
    public function onRestored(LegalDocumentRestoredEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_RESTORED, context: ['slug' => $event->slug, 'locale' => $event->locale]);
    }

    /**
     * Logs an import.
     *
     * @return void Nothing (side effects only)
     */
    public function onImported(LegalDocumentsImportedEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_IMPORTED, context: ['count' => count($event->documents)]);
    }

    /**
     * Records a published version.
     *
     * @return void Nothing (side effects only)
     */
    public function onVersionPublished(LegalVersionPublishedEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_VERSION_PUBLISHED, context: ['slug' => $event->slug, 'version' => $event->version, 'requires_acceptance' => $event->requiresAcceptance, 'source' => $event->source]);
    }

    /**
     * Records "Keep my text".
     *
     * @return void Nothing (side effects only)
     */
    public function onRebased(LegalDocumentRebasedEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_REBASED, context: ['slug' => $event->slug, 'locale' => $event->locale, 'seed_revision' => $event->seedRevision]);
    }

    /**
     * Records a seed upgrade (counts only).
     *
     * @return void Nothing (side effects only)
     */
    public function onSeedUpgraded(LegalSeedUpgradedEvent $event): void
    {
        $this->recorder->recordAndFlush(self::ACTION_SEED_UPGRADED, context: ['revision' => $event->revision, 'from' => $event->from] + $event->counts);
    }

    /**
     * Records an acceptance only with `acceptance.action_log: true` (the host otherwise audits the event itself and
     * owns that copy's erasure). The subject id is not written: the action log records the signed-in actor.
     *
     * @return void Nothing (side effects only)
     */
    public function onAccepted(LegalAcceptedEvent $event): void
    {
        if (!$this->logAcceptances) {
            return;
        }
        $this->recorder->recordAndFlush(self::ACTION_ACCEPTED, context: ['slug' => $event->slug, 'version' => $event->version, 'kind' => $event->kind, 'locale' => $event->locale]);
    }
}
