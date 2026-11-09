<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

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
     * {@see LegalAcceptedEvent} for each new one at once. Outside a transaction each insert is autocommitted, so the
     * events follow the commit; inside a host transaction use {@see recordInTransaction()} instead.
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
     * Personal-data export of a subject.
     *
     * @return list<array{page: string, version: int, locale: string, kind: string, accepted_at: string, content_hash: string}>
     */
    public function exportFor(string $subjectId): array;

    /**
     * Erasure of a subject: deletes every record, keeps no copy.
     *
     * @return int Rows deleted
     */
    public function eraseFor(string $subjectId): int;
}
