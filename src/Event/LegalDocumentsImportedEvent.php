<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an import / ops-seed apply flushed (public API, SemVer). With `flush: false` the host dispatches
 * nothing itself: the bundle dispatches it from {@see LegalDocumentPortability::apply()}
 * only when it flushed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalDocumentsImportedEvent extends Event
{
    /**
     * @param list<array{slug: string, locale: string}> $documents Upserted pages
     */
    public function __construct(
        public readonly array $documents,
    ) {
    }
}
