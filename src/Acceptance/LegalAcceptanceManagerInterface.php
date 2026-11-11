<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use Nowo\LegalKitBundle\Event\LegalAcceptancesErasedEvent;
use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;

/**
 * Port of the acceptance records (public API, SemVer): pending pages, recording, personal-data export and erasure of
 * a subject. Aliased to {@see LegalAcceptanceManager}; type-hint this interface in your application layer and
 * decorate it if needed.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalAcceptanceManagerInterface
{
    /**
     * Pages the subject still has to accept or acknowledge, with the text offered in the locale.
     *
     * @return list<PendingLegalPage>
     */
    public function pendingFor(string $subjectId, ?string $locale = null): array;

    /**
     * Records one row per page (insert-only; an existing row of the same version counts as recorded) and dispatches
     * {@see LegalAcceptedEvent} for each new one once the rows are committed. Outside a transaction the batch runs in
     * one transaction of its own (all pages or none, since 1.10) and the events follow its commit. Inside a host
     * transaction the rows join it and, since 1.10, the events wait for its outermost commit (dropped on rollback);
     * {@see recordInTransaction()} + {@see afterCommit()} keep the dispatch in your hands.
     *
     * @param list<PendingLegalPage> $pages
     *
     * @return int Rows inserted
     */
    public function record(string $subjectId, array $pages): int;

    /**
     * Records like {@see record()} inside the caller's open transaction (one savepoint per row, so a duplicate does
     * not abort it) and dispatches nothing: call {@see afterCommit()} with the returned batch after your commit.
     *
     * @param list<PendingLegalPage> $pages
     *
     * @return LegalAcceptanceBatch The inserted rows and their pending events
     */
    public function recordInTransaction(string $subjectId, array $pages): LegalAcceptanceBatch;

    /**
     * After the host transaction committed: dispatches the {@see LegalAcceptedEvent}s of the batches.
     *
     * @return void Nothing (side effects only)
     */
    public function afterCommit(LegalAcceptanceBatch ...$batches): void;

    /**
     * Personal-data export of a subject. Since 1.11 the subject id matches exactly (never a case / accent look-alike).
     * With UserKit installed, the `legal_acceptances` export section calls it for you (see docs/USAGE.md).
     *
     * @return list<array{page: string, version: int, locale: string, kind: string, accepted_at: string, content_hash: string}>
     */
    public function exportFor(string $subjectId): array;

    /**
     * Erasure of a subject: deletes every record, keeps no copy. Since 1.10 dispatches
     * {@see LegalAcceptancesErasedEvent} once the deletion is committed (after the
     * outermost commit of a host transaction). Since 1.11: exact subject id match, idempotent, its own transaction
     * when none is open; with UserKit installed the `legal_acceptances` eraser calls it for you (see docs/USAGE.md).
     *
     * @return int Rows deleted
     */
    public function eraseFor(string $subjectId): int;
}
