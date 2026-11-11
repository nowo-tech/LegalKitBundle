<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Portability;

use InvalidArgumentException;
use Nowo\LegalKitBundle\Blame\LegalActorId;
use Nowo\LegalKitBundle\Event\LegalDocumentsImportedEvent;

/**
 * Port of the ops-seed portability of operator copies (public API, SemVer): `legal_documents[]` = list of
 * {slug, locale, title, body}. Aliased to {@see LegalDocumentPortability}; type-hint this interface in your
 * application layer and decorate it if needed (the bundle's export / import commands use it too).
 *
 * No access check: {@see apply()} writes whatever it is given (sanitised); the caller decides who may import.
 *
 * @phpstan-type LegalDocumentRow array{slug: string, locale: string, title: string, body: string}
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalDocumentPortabilityInterface
{
    /**
     * Every stored row, sorted by slug and locale (tokens kept).
     *
     * @return list<LegalDocumentRow>
     */
    public function export(): array;

    /**
     * Validates a decoded `legal_documents` payload.
     *
     * @throws InvalidArgumentException With the index of the first invalid entry
     *
     * @return list<LegalDocumentRow>
     */
    public function normalize(mixed $payload): array;

    /**
     * Upserts normalised rows (sanitised bodies) and returns how many were written. With `$flush` it flushes and
     * dispatches {@see LegalDocumentsImportedEvent}; without, the host transaction stays open and the caller calls
     * {@see afterCommit()} with the same rows once it committed. 1.12: with the bundle's private manager
     * (`doctrine.entity_manager` unset) the rows are written at once even without `$flush`, inside the caller's open
     * transaction when there is one; your own entity manager is never flushed.
     *
     * Prefer the typed {@see LegalDocumentImportInterface::import()} (1.12); 2.0 narrows `$actor` to `?LegalActorId`.
     *
     * @param list<LegalDocumentRow> $rows Output of {@see normalize()}
     * @param object|null $actor Since 1.10 a {@see LegalActorId} (the actor by id, for
     *                           `doctrine.blame: actor_id`); passing a user object is deprecated (removed in 2.0)
     * @param bool $flush Flush and dispatch the event
     *
     * @return int Number of rows written
     */
    public function apply(array $rows, ?object $actor = null, bool $flush = false): int;

    /**
     * After the host committed an {@see apply()} made with `flush: false`: dispatches
     * {@see LegalDocumentsImportedEvent} for the rows (an empty list included).
     *
     * @param list<array{slug: string, locale: string, ...}> $rows The rows given to apply() (only slug and locale are read)
     *
     * @return void Nothing (side effects only)
     */
    public function afterCommit(array $rows): void;
}
