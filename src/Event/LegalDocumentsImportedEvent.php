<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an import / ops-seed apply flushed (public API, SemVer): by
 * {@see LegalDocumentPortability::apply()} with `flush: true`, or by {@see LegalDocumentPortability::afterCommit()}
 * once the host committed an `apply(..., flush: false)` (since 1.8).
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
