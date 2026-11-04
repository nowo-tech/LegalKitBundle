<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\LegalKitBundle\Catalog\LegalPageCatalogInterface;
use Nowo\LegalKitBundle\Entity\LegalDocument;
use Nowo\LegalKitBundle\Event\LegalSeedUpgradedEvent;
use Nowo\LegalKitBundle\Locale\LegalLocales;
use Nowo\LegalKitBundle\Seed\LegalSeed;
use Nowo\LegalKitBundle\Seed\TwigSeedProvider;
use Nowo\LegalKitBundle\Versioning\Entity\LegalPageVersion;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_fill_keys;
use function in_array;
use function sprintf;
use function trim;

/**
 * nowo:legal-kit:upgrade-seed: refreshes the stored copies that still equal the seed they started from, flags the
 * edited ones as outdated, and optionally publishes one new version per changed page. One transaction; rows re-read
 * with a write lock; --dry-run rolls back. Never overwrites a copy whose base is unknown.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LegalSeedUpgrader
{
    public const string REPLACED = 'replaced';

    public const string OUTDATED = 'outdated';

    public const string CURRENT = 'current';

    public const string NO_SEED = 'no_seed';

    public const string SEED_CHANGED = 'seed_changed';

    public const string SEED_SAME = 'seed_same';

    public const string SEED_UNKNOWN = 'seed_unknown';

    /** Every result, in report order. */
    public const array RESULTS = [self::REPLACED, self::OUTDATED, self::CURRENT, self::SEED_CHANGED, self::SEED_SAME, self::SEED_UNKNOWN, self::NO_SEED];

    /** No version is published (the change counts as a minor correction). */
    public const string VERSIONS_NONE = 'none';

    /** One new version per changed page, without acceptance. */
    public const string VERSIONS_NEW = 'new';

    /** One new version per changed page, requiring acceptance on pages whose mode is not none. */
    public const string VERSIONS_ACCEPTANCE = 'acceptance';

    /** Optional seed key (NowoLegalKitSeed domain) of the change summary of a page. */
    public const string SUMMARY_KEY = '%s.change_summary';

    /**
     * @param LegalPageCatalogInterface $catalog Enabled pages
     * @param LegalLocales $locales Enabled locales
     * @param EntityManagerInterface $entityManager Entity manager
     * @param LegalSeedTracker $tracker Seed base helper
     * @param LegalSeedHasher $hasher Text hash
     * @param SeedSnapshotStore $snapshots Frozen seeds
     * @param LegalPageVersions $versions Page versions
     * @param TranslatorInterface $translator Change summaries (seed domain)
     * @param ?EventDispatcherInterface $dispatcher Event dispatcher
     * @param LoggerInterface $logger Logger (counts only)
     */
    public function __construct(
        private LegalPageCatalogInterface $catalog,
        private LegalLocales $locales,
        private EntityManagerInterface $entityManager,
        private LegalSeedTracker $tracker,
        private LegalSeedHasher $hasher,
        private SeedSnapshotStore $snapshots,
        private LegalPageVersions $versions,
        private TranslatorInterface $translator,
        private ?EventDispatcherInterface $dispatcher = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Stored rows without a seed base (they need --from).
     *
     * @return list<array{slug: string, locale: string}>
     */
    public function rowsWithoutBase(): array
    {
        $rows = [];
        foreach ($this->entityManager->getRepository(LegalDocument::class)->findBy(['seedRevision' => null], ['slug' => 'ASC', 'locale' => 'ASC']) as $document) {
            if ($this->catalog->has($document->getSlug()) && $this->locales->has($document->getLocale())) {
                $rows[] = ['slug' => $document->getSlug(), 'locale' => $document->getLocale()];
            }
        }

        return $rows;
    }

    /**
     * Runs the upgrade.
     *
     * @param ?string $from Base revision of rows without one (and of pages without a row)
     * @param string $versionMode none|new|acceptance
     *
     * @throws Throwable Database errors (the transaction is rolled back)
     *
     * @return array{rows: list<array{slug: string, locale: string, result: string}>, counts: array<string, int>, versions: list<array{slug: string, version: int, requires_acceptance: bool}>}
     */
    public function run(?string $from, string $versionMode = self::VERSIONS_NONE, bool $dryRun = false): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            [$report, $published] = $this->upgrade($from, $versionMode, $dryRun);
            if ($dryRun) {
                $connection->rollBack();
                $this->entityManager->clear();
            } else {
                $connection->commit();
                // Post-commit: drop a map re-cached by a concurrent request meanwhile, then dispatch the events.
                $this->versions->afterCommit(...$published);
            }
        } catch (Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->clear();

            throw $exception;
        }

        if (!$dryRun) {
            $this->logger->info('Legal seed upgraded.', ['bundle' => 'NowoLegalKitBundle', 'action' => 'seed_upgraded'] + $report['counts']);
            $this->dispatcher?->dispatch(new LegalSeedUpgradedEvent($this->tracker->revision(), $from, $report['counts']));
        }

        return $report;
    }

    /**
     * @return array{0: array{rows: list<array{slug: string, locale: string, result: string}>, counts: array<string, int>, versions: list<array{slug: string, version: int, requires_acceptance: bool}>}, 1: list<LegalPageVersion>}
     */
    private function upgrade(?string $from, string $versionMode, bool $dryRun): array
    {
        $current   = $this->tracker->revision();
        $rows      = [];
        $counts    = array_fill_keys(self::RESULTS, 0);
        $versions  = [];
        $published = [];
        foreach ($this->catalog->pages() as $page) {
            $changed = false;
            foreach ($this->locales->all() as $locale) {
                $result = $this->upgradeOne($page->slug, $locale, $from, $current);
                if ($result === null) {
                    continue;
                }
                $rows[] = ['slug' => $page->slug, 'locale' => $locale, 'result' => $result];
                ++$counts[$result];
                $changed = $changed || in_array($result, [self::REPLACED, self::SEED_CHANGED, self::SEED_UNKNOWN], true);
            }
            $this->entityManager->flush();

            if ($versionMode === self::VERSIONS_NONE || !$changed || $this->versions->hasSeedRevision($page->slug, $current)) {
                continue;
            }
            $requires = $versionMode === self::VERSIONS_ACCEPTANCE && $page->requiresAcceptance();
            if ($dryRun) {
                $versions[] = ['slug' => $page->slug, 'version' => $this->versions->currentUncached($page->slug) + 1, 'requires_acceptance' => $requires];

                continue;
            }
            $version     = $this->versions->publish($page->slug, $requires, LegalPageVersion::SOURCE_UPGRADE, $this->summaries($page->slug), $current);
            $published[] = $version;
            $versions[]  = ['slug' => $page->slug, 'version' => $version->getVersion(), 'requires_acceptance' => $requires];
        }

        return [['rows' => $rows, 'counts' => $counts, 'versions' => $versions], $published];
    }

    /**
     * Result of one page / locale, null when there is neither a row nor a seed.
     */
    private function upgradeOne(string $slug, string $locale, ?string $from, string $current): ?string
    {
        $document = $this->entityManager->getRepository(LegalDocument::class)
            ->createQueryBuilder('d')
            ->where('d.slug = :slug')
            ->andWhere('d.locale = :locale')
            ->setParameter('slug', $slug)
            ->setParameter('locale', $locale)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
        $seed = $this->tracker->currentSeed($slug, $locale);

        if (!$document instanceof LegalDocument) {
            if (!$seed instanceof LegalSeed) {
                return null;
            }
            $old = $from === null ? null : $this->snapshots->hash($from, $slug, $locale);
            if ($old === null) {
                return self::SEED_UNKNOWN;
            }

            return $old === $this->hasher->hash($seed->title, $seed->body) ? self::SEED_SAME : self::SEED_CHANGED;
        }
        if (!$seed instanceof LegalSeed) {
            return self::NO_SEED;
        }

        $newHash = $this->hasher->hash($seed->title, $seed->body);
        $rowHash = $this->tracker->documentHash($document);
        if ($rowHash === $newHash) {
            $document->setSeedBase($current, $newHash);

            return self::CURRENT;
        }

        $baseRevision = $document->getSeedRevision() ?? $from;
        $base         = $document->getSeedHash() ?? ($baseRevision === null ? null : $this->snapshots->hash($baseRevision, $slug, $locale));
        if ($base !== null && $rowHash === $base) {
            $document->setTitle($seed->title);
            $document->setBody($seed->body);
            $document->setSeedBase($current, $newHash);

            return self::REPLACED;
        }
        if ($document->getSeedRevision() === null) {
            $document->setSeedBase($from, $document->getSeedHash());
        }

        return self::OUTDATED;
    }

    /**
     * Locale => change summary from the optional seed key `<slug>.change_summary` (only locales that define it).
     *
     * @return array<string, string>
     */
    private function summaries(string $slug): array
    {
        $key       = sprintf(self::SUMMARY_KEY, $slug);
        $summaries = [];
        foreach ($this->locales->all() as $locale) {
            if ($this->translator instanceof TranslatorBagInterface && !$this->translator->getCatalogue($locale)->defines($key, TwigSeedProvider::DOMAIN)) {
                continue;
            }
            $text = trim($this->translator->trans($key, [], TwigSeedProvider::DOMAIN, $locale));
            if ($text !== '' && $text !== $key) {
                $summaries[$locale] = $text;
            }
        }

        return $summaries;
    }
}
