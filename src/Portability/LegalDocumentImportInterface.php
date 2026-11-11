<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Portability;

use Nowo\LegalKitBundle\Blame\LegalActorId;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;

/**
 * Typed import port (1.12, architecture bar rule 3): the twin of {@see LegalDocumentPortabilityInterface::apply()},
 * whose `?object $actor` also accepts a user object (deprecated since 1.10). Aliased to {@see LegalDocumentPortability};
 * in 2.0 `import()` joins {@see LegalDocumentPortabilityInterface} and `apply()` takes `?LegalActorId` only.
 *
 * @phpstan-import-type LegalDocumentRow from LegalDocumentPortabilityInterface
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalDocumentImportInterface
{
    /**
     * Upserts normalised rows (sanitised bodies) and returns how many were written. With `$flush` it flushes and
     * dispatches {@see LegalDocumentsImportedEvent}; without, the caller calls
     * {@see LegalDocumentPortabilityInterface::afterCommit()} with the same rows once its transaction committed (with the
     * bundle's private manager the rows are written at once, inside that transaction when one is open).
     *
     * @param list<LegalDocumentRow> $rows Output of {@see LegalDocumentPortabilityInterface::normalize()}
     * @param LegalActorId|null $actor The actor by id (`doctrine.blame: actor_id`), or null
     * @param bool $flush Flush and dispatch the event
     *
     * @return int Number of rows written
     */
    public function import(array $rows, ?LegalActorId $actor = null, bool $flush = false): int;
}
