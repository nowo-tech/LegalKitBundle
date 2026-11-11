<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Nowo\LegalKitBundle\Acceptance\LegalAcceptanceManagerInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after {@see LegalAcceptanceManagerInterface::eraseFor()} deleted the acceptance records of a subject
 * (1.10, public API, SemVer), once the deletion is committed: at once outside a transaction, after the outermost commit
 * of a transaction the host opened around the call (dropped on rollback). Also when nothing was deleted (`count` 0).
 * Carries the host subject id only: a listener that keeps its own copy of the acceptances erases it here.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalAcceptancesErasedEvent extends Event
{
    /**
     * @param string $subjectId Host subject id
     * @param int $count Rows deleted
     */
    public function __construct(
        public readonly string $subjectId,
        public readonly int $count,
    ) {
    }
}
