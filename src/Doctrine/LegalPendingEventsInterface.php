<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Doctrine;

/**
 * The bundle's committed-but-not-yet-dispatched events (1.10, public API, SemVer; architecture bar rule 7).
 *
 * After the outermost commit of a transaction on the bundle's connection, the events it held (`LegalAcceptedEvent`,
 * `LegalAcceptancesErasedEvent`) are dispatched at the next point where DBAL reports no open transaction: the next
 * bundle event, a Doctrine `postFlush`, or the end of the request, console command or Messenger message. A host that
 * commits in the middle of a long process and wants the events right away calls {@see dispatchPending()} after its
 * commit returned.
 */
interface LegalPendingEventsInterface
{
    /**
     * Dispatches the committed events when the bundle's connection has no open transaction.
     *
     * @return int Events dispatched (0 inside a transaction or when nothing is pending)
     */
    public function dispatchPending(): int;

    /**
     * Events held by open transactions plus committed events not dispatched yet.
     */
    public function countPending(): int;
}
