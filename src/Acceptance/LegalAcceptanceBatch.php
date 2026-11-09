<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;

use function count;

/**
 * Acceptances written by {@see LegalAcceptanceManagerInterface::recordInTransaction()} whose
 * {@see LegalAcceptedEvent}s are not dispatched yet: pass it to
 * {@see LegalAcceptanceManagerInterface::afterCommit()} once the host transaction committed (drop it on rollback).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalAcceptanceBatch
{
    /**
     * @param string $subjectId Subject of the acceptances
     * @param list<LegalAcceptedEvent> $events One event per inserted row, in page order
     */
    public function __construct(
        public string $subjectId,
        public array $events,
    ) {
    }

    /**
     * Rows inserted.
     *
     * @return int The count
     */
    public function count(): int
    {
        return count($this->events);
    }
}
