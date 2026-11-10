<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Acceptance\Entity\LegalAcceptance;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Document\LegalDocumentResolverInterface;
use Nowo\LegalKitBundle\Document\ResolvedLegalDocument;
use Nowo\LegalKitBundle\Event\LegalAcceptedEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Sanitizer\DomAllowlistSanitizer;
use Nowo\LegalKitBundle\Versioning\LegalPageVersions;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

use function count;
use function hash;
use function implode;
use function max;
use function sort;

/**
 * Pending pages, acceptance records and the personal-data hooks (export / erasure) of a subject. Type-hint
 * {@see LegalAcceptanceManagerInterface} (aliased to this class) rather than the class.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalAcceptanceManager implements LegalAcceptanceManagerInterface
{
    /** Savepoint of one insert inside the caller's transaction. */
    private const string SAVEPOINT = 'nowo_legal_kit_acceptance';

    private ClockInterface $clock;

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages (with their acceptance mode)
     * @param LegalLocales $locales Enabled locales
     * @param LegalPageVersions $versions Page versions
     * @param LegalAcceptanceRepository $acceptances Records
     * @param LegalDocumentResolverInterface $resolver Published texts
     * @param EntityManagerInterface $entityManager Entity manager (DBAL inserts)
     * @param ?EventDispatcherInterface $dispatcher Event dispatcher
     * @param ?ClockInterface $clock Clock (null = native)
     * @param LoggerInterface $logger Logger (never the subject)
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private LegalPageVersions $versions,
        private LegalAcceptanceRepository $acceptances,
        private LegalDocumentResolverInterface $resolver,
        private EntityManagerInterface $entityManager,
        private ?EventDispatcherInterface $dispatcher = null,
        ?ClockInterface $clock = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Pages the subject still has to accept or acknowledge, with the text offered in the locale (pages without any
     * text are left out: they could never be accepted).
     *
     * @return list<PendingLegalPage>
     */
    public function pendingFor(string $subjectId, ?string $locale = null): array
    {
        $locale   = $locale !== null && $this->locales->has($locale) ? $locale : $this->locales->defaultLocale();
        $accepted = null;
        $map      = null;
        $pending  = [];
        foreach ($this->catalog->pages() as $page) {
            if (!$page->requiresAcceptance()) {
                continue;
            }
            $map ??= $this->versions->requirements();
            $required = max(1, $map[$page->slug][1] ?? 1);
            $accepted ??= $this->acceptances->acceptedVersions($subjectId);
            $version = $accepted[$page->slug] ?? null;
            if ($version !== null && $version >= $required) {
                continue;
            }
            $document = $this->resolver->resolve($page->slug, $locale);
            if (!$document instanceof ResolvedLegalDocument) {
                $this->logger->warning('Legal page without text cannot be accepted.', ['bundle' => 'NowoLegalKitBundle', 'action' => 'acceptance_page_unavailable', 'slug' => $page->slug]);

                continue;
            }
            $current   = max(1, $map[$page->slug][0] ?? 1);
            $pending[] = new PendingLegalPage(
                $page,
                $current,
                $required,
                $page->acceptance,
                $version,
                $document->title,
                $document->contentLocale,
                self::contentHash($document),
                $this->summary($page->slug, $current, $document->contentLocale, $locale),
            );
        }

        return $pending;
    }

    /**
     * Fingerprint of an offer (pages, versions and text hashes): the accept form must post back the same one.
     *
     * @param list<PendingLegalPage> $pages
     *
     * @return string SHA-256 hex
     */
    public static function offerFingerprint(array $pages): string
    {
        $parts = [];
        foreach ($pages as $page) {
            $parts[] = $page->page->slug . ':' . $page->currentVersion . ':' . $page->contentHash;
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * SHA-256 of a resolved title and body (the text a subject was offered).
     *
     * @return string The hash
     */
    public static function contentHash(ResolvedLegalDocument $document): string
    {
        return hash('sha256', $document->title . "\n" . $document->body);
    }

    /**
     * Hash of the same text as printed with `sanitizer.heading_anchors` empty: the hash an acceptance recorded before
     * the option was turned on. Never recorded; `nowo:legal-kit:verify-acceptance-hash` compares with it.
     *
     * @return string The hash
     */
    public static function contentHashWithoutAnchorIds(ResolvedLegalDocument $document): string
    {
        return hash('sha256', $document->title . "\n" . DomAllowlistSanitizer::withoutAnchorIds($document->body));
    }

    /**
     * Records one row per page (insert-only; an existing row of the same version counts as recorded) and dispatches
     * {@see LegalAcceptedEvent} for each new one.
     *
     * @param list<PendingLegalPage> $pages
     *
     * @return int Rows inserted
     */
    public function record(string $subjectId, array $pages): int
    {
        $batch = $this->insert($subjectId, $pages);
        $this->afterCommit($batch);

        return $batch->count();
    }

    /**
     * Records inside the caller's transaction and dispatches nothing: pass the batch to {@see afterCommit()} after the
     * commit (savepoint per row, so a duplicate does not abort the caller's transaction).
     *
     * @param list<PendingLegalPage> $pages
     *
     * @return LegalAcceptanceBatch Inserted rows and their pending events
     */
    public function recordInTransaction(string $subjectId, array $pages): LegalAcceptanceBatch
    {
        return $this->insert($subjectId, $pages);
    }

    /**
     * Dispatches the {@see LegalAcceptedEvent}s of the batches (after the host commit).
     *
     * @return void Nothing (side effects only)
     */
    public function afterCommit(LegalAcceptanceBatch ...$batches): void
    {
        foreach ($batches as $batch) {
            foreach ($batch->events as $event) {
                $this->dispatcher?->dispatch($event);
            }
        }
    }

    /**
     * Personal-data export of a subject (host export providers).
     *
     * @return list<array{page: string, version: int, locale: string, kind: string, accepted_at: string, content_hash: string}>
     */
    public function exportFor(string $subjectId): array
    {
        $rows = [];
        foreach ($this->acceptances->forSubject($subjectId) as $acceptance) {
            $rows[] = [
                'page'         => $acceptance->getSlug(),
                'version'      => $acceptance->getVersion(),
                'locale'       => $acceptance->getLocale(),
                'kind'         => $acceptance->getKind(),
                'accepted_at'  => $acceptance->getAcceptedAt()->format(DateTimeInterface::ATOM),
                'content_hash' => $acceptance->getContentHash(),
            ];
        }

        return $rows;
    }

    /**
     * Erasure of a subject (host erasers): deletes every record, keeps no copy.
     *
     * @return int Rows deleted
     */
    public function eraseFor(string $subjectId): int
    {
        return $this->acceptances->deleteForSubject($subjectId);
    }

    /**
     * Inserts one row per page. Outside a transaction each insert is autocommitted (no shared transaction: on
     * PostgreSQL a unique violation would abort it); inside the caller's transaction each insert runs in a savepoint
     * (when the platform has them), so a duplicate rolls back only that row.
     *
     * @param list<PendingLegalPage> $pages
     */
    private function insert(string $subjectId, array $pages): LegalAcceptanceBatch
    {
        $metadata   = $this->entityManager->getClassMetadata(LegalAcceptance::class);
        $connection = $this->entityManager->getConnection();
        $savepoint  = $connection->isTransactionActive() && $connection->getDatabasePlatform()->supportsSavepoints();
        $now        = $this->clock->now();
        $events     = [];
        foreach ($pages as $page) {
            if ($savepoint) {
                $connection->createSavepoint(self::SAVEPOINT);
            }
            try {
                $connection->insert($metadata->getTableName(), [
                    'subject_id'   => $subjectId,
                    'slug'         => $page->page->slug,
                    'version'      => $page->currentVersion,
                    'locale'       => $page->contentLocale,
                    'kind'         => $page->kind,
                    'content_hash' => $page->contentHash,
                    'accepted_at'  => $now,
                ], ['accepted_at' => Types::DATETIME_IMMUTABLE, 'version' => Types::INTEGER]);
                if ($savepoint) {
                    $connection->releaseSavepoint(self::SAVEPOINT);
                }
                $events[] = new LegalAcceptedEvent($subjectId, $page->page->slug, $page->currentVersion, $page->contentLocale, $page->kind, $now, $page->contentHash);
            } catch (UniqueConstraintViolationException) {
                // Double submit or a second tab: the same version is already recorded.
                if ($savepoint) {
                    $connection->rollbackSavepoint(self::SAVEPOINT);
                }
            }
        }
        $this->logger->info('Legal pages accepted.', ['bundle' => 'NowoLegalKitBundle', 'action' => 'acceptance_recorded', 'count' => count($events)]);

        return new LegalAcceptanceBatch($subjectId, $events);
    }

    /**
     * Summary of the current version: content locale, request locale, default locale; null for version 1 or none.
     */
    private function summary(string $slug, int $current, string $contentLocale, string $locale): ?string
    {
        if ($current < 2) {
            return null;
        }
        $latest = $this->versions->latest($slug);

        return $latest?->summaryFor($contentLocale) ?? $latest?->summaryFor($locale) ?? $latest?->summaryFor($this->locales->defaultLocale());
    }
}
