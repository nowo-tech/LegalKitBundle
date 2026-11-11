<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Doctrine\LegalKitEntityManager;
use Nowo\LegalKitBundle\Event\LegalVersionPublishedEvent;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function hash;
use function json_encode;
use function max;

use const JSON_THROW_ON_ERROR;

/**
 * Version numbers of the legal pages: `current(slug)` = max(1, highest version), `required(slug)` = max(1, highest
 * version requiring acceptance); version 1 is implicit. The requirements map is cached in the configured pool and
 * deleted on every publish (worker-safe: no in-memory state). Type-hint {@see LegalPageVersionsInterface} (1.10): its
 * methods use {@see LegalPageVersionView} DTOs; the entity-typed {@see latest()}, {@see publish()} and
 * {@see afterCommit()} are deprecated and removed in 2.0.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalPageVersions implements LegalPageVersionsInterface
{
    /** Cache key prefix of the requirements map (the table prefix is appended). */
    public const string CACHE_KEY = 'nowo_legal_kit.acceptance.requirements';

    /** Backstop lifetime of the cached map (seconds). */
    public const int CACHE_TTL = 3600;

    private ClockInterface $clock;

    /**
     * @param LegalPageVersionRepository $versions Version rows
     * @param EntityManagerInterface $entityManager The bundle's entity manager (`doctrine.entity_manager`; persist + flush on publish)
     * @param ?CacheInterface $cache Requirements cache (null = no cache)
     * @param ?EventDispatcherInterface $dispatcher Event dispatcher
     * @param ?ClockInterface $clock Clock (null = native)
     * @param string $tablePrefix doctrine.table_prefix (part of the cache key)
     */
    public function __construct(
        private LegalPageVersionRepository $versions,
        private EntityManagerInterface $entityManager,
        private ?CacheInterface $cache = null,
        private ?EventDispatcherInterface $dispatcher = null,
        ?ClockInterface $clock = null,
        private string $tablePrefix = '',
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    /**
     * Slug => [current, required] of every page that has a version row (pages without one are at [1, 1]).
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function requirements(): array
    {
        if (!$this->cache instanceof CacheInterface) {
            return $this->versions->requirementsMap();
        }

        /* @var array<string, array{0: int, 1: int}> */
        return $this->cache->get($this->cacheKey(), function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->versions->requirementsMap();
        });
    }

    /**
     * Fingerprint of the requirements map (changes whenever a version is published).
     *
     * @return string SHA-256 hex
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->requirements(), JSON_THROW_ON_ERROR));
    }

    /**
     * Current version of a page (≥ 1).
     *
     * @return int The version
     */
    public function current(string $slug): int
    {
        return max(1, $this->requirements()[$slug][0] ?? 1);
    }

    /**
     * Lowest version a subject must have accepted (≥ 1).
     *
     * @return int The version
     */
    public function required(string $slug): int
    {
        return max(1, $this->requirements()[$slug][1] ?? 1);
    }

    /**
     * Current version read from the database (no cache; editor guards).
     *
     * @return int The version
     */
    public function currentUncached(string $slug): int
    {
        return max(1, $this->versions->requirementsMap()[$slug][0] ?? 1);
    }

    /**
     * The highest version row of a page (null = implicit version 1).
     *
     * @deprecated since 1.10, use {@see latestVersion()} (DTO); removed in 2.0
     *
     * @return ?LegalPageVersion The row
     */
    public function latest(string $slug): ?LegalPageVersion
    {
        return $this->versions->latest($slug);
    }

    /**
     * The highest published version of a page as a view (null = implicit version 1).
     *
     * @return ?LegalPageVersionView The view
     */
    public function latestVersion(string $slug): ?LegalPageVersionView
    {
        $latest = $this->versions->latest($slug);

        return $latest instanceof LegalPageVersion ? LegalPageVersionView::fromEntity($latest) : null;
    }

    /**
     * Whether upgrade-seed already published a version of the page for this seed revision.
     *
     * @return bool The answer
     */
    public function hasSeedRevision(string $slug, string $revision): bool
    {
        return $this->versions->hasSeedRevision($slug, $revision);
    }

    /**
     * Publishes a version (persist + flush) and deletes the cached map.
     *
     * Inside a transaction (the editor save, upgrade-seed) the caller must call {@see afterCommit()} once the
     * transaction committed: it deletes the cached map again (a concurrent request may have re-cached the old,
     * still committed map in between) and dispatches {@see LegalVersionPublishedEvent}. Outside a transaction the
     * event is dispatched at once.
     *
     * @param array<string, string> $changeSummary Locale => plain-text summary
     * @param ?int $version Version number to publish (the editor passes base + 1, so a concurrent publish of the
     *                      same number fails on the unique key instead of stacking); null = current + 1
     *
     * @deprecated since 1.10, use {@see publishVersion()} (DTO, `flush` option); removed in 2.0
     *
     * @return LegalPageVersion The new version
     */
    public function publish(string $slug, bool $requiresAcceptance, string $source, array $changeSummary = [], ?string $seedRevision = null, ?int $version = null): LegalPageVersion
    {
        return $this->write($slug, $requiresAcceptance, $source, $changeSummary, $seedRevision, $version, true);
    }

    /**
     * Publishes a version and returns its view; with `$flush` false it only persists (the host flushes, commits and
     * calls {@see afterPublish()}).
     *
     * @param array<string, string> $changeSummary Locale => plain-text summary
     * @param ?int $version Version number to publish; null = current + 1
     * @param bool $flush Persist and flush (true), or persist only (false)
     *
     * @return LegalPageVersionView The new version
     */
    public function publishVersion(string $slug, bool $requiresAcceptance, string $source, array $changeSummary = [], ?string $seedRevision = null, ?int $version = null, bool $flush = true): LegalPageVersionView
    {
        return LegalPageVersionView::fromEntity($this->write($slug, $requiresAcceptance, $source, $changeSummary, $seedRevision, $version, $flush));
    }

    /**
     * After the publishing transaction committed: deletes the cached map again and dispatches one
     * {@see LegalVersionPublishedEvent} per version.
     *
     * @deprecated since 1.10, use {@see afterPublish()} (DTO); removed in 2.0
     *
     * @return void Nothing (side effects only)
     */
    public function afterCommit(LegalPageVersion ...$published): void
    {
        $views = [];
        foreach ($published as $version) {
            $views[] = LegalPageVersionView::fromEntity($version);
        }
        $this->afterPublish(...$views);
    }

    /**
     * After the publishing transaction committed: deletes the cached map again and dispatches one
     * {@see LegalVersionPublishedEvent} per version.
     *
     * @return void Nothing (side effects only)
     */
    public function afterPublish(LegalPageVersionView ...$published): void
    {
        $this->invalidate();
        foreach ($published as $version) {
            $this->dispatcher?->dispatch(new LegalVersionPublishedEvent($version->slug, $version->version, $version->requiresAcceptance, $version->source, $version->seedRevision));
        }
    }

    /**
     * Deletes the cached requirements map.
     *
     * @return void Nothing (side effects only)
     */
    public function invalidate(): void
    {
        $this->cache?->delete($this->cacheKey());
    }

    /**
     * @param array<string, string> $changeSummary
     */
    private function write(string $slug, bool $requiresAcceptance, string $source, array $changeSummary, ?string $seedRevision, ?int $version, bool $flush): LegalPageVersion
    {
        $row = new LegalPageVersion($slug, $version ?? $this->currentUncached($slug) + 1, $requiresAcceptance, $source, $this->clock->now(), $seedRevision, $changeSummary);
        $this->entityManager->persist($row);
        if (!$flush) {
            // 1.12 (rule 6): the private manager holds only bundle rows and nobody else flushes it, so the row is
            // written now (inside the caller's open transaction, if any); the caller still calls afterPublish().
            if ($this->entityManager instanceof LegalKitEntityManager) {
                $this->entityManager->flush();
            }

            return $row;
        }
        $this->entityManager->flush();
        $this->invalidate();
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            $this->afterPublish(LegalPageVersionView::fromEntity($row));
        }

        return $row;
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY . '.' . ($this->tablePrefix === '' ? 'default' : $this->tablePrefix);
    }
}
