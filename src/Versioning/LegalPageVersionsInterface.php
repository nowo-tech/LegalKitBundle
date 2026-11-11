<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Nowo\LegalKitBundle\Event\LegalVersionPublishedEvent;

/**
 * Port of the legal page versions (1.10, public API, SemVer; architecture bar rule 1): version numbers, the cached
 * requirements map and publication, with {@see LegalPageVersionView} DTOs instead of the Doctrine entity. Aliased to
 * {@see LegalPageVersions} when `versioning.enabled` is true; type-hint this interface in your application layer.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalPageVersionsInterface
{
    /**
     * Slug => [current, required] of every page that has a version row (pages without one are at [1, 1]).
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function requirements(): array;

    /**
     * Fingerprint of the requirements map (changes whenever a version is published).
     *
     * @return string SHA-256 hex
     */
    public function fingerprint(): string;

    /**
     * Current version of a page (≥ 1).
     *
     * @return int The version
     */
    public function current(string $slug): int;

    /**
     * Lowest version a subject must have accepted (≥ 1).
     *
     * @return int The version
     */
    public function required(string $slug): int;

    /**
     * Current version read from the database (no cache).
     *
     * @return int The version
     */
    public function currentUncached(string $slug): int;

    /**
     * The highest published version of a page (null = implicit version 1).
     *
     * @return ?LegalPageVersionView The view
     */
    public function latestVersion(string $slug): ?LegalPageVersionView;

    /**
     * Whether upgrade-seed already published a version of the page for this seed revision.
     *
     * @return bool The answer
     */
    public function hasSeedRevision(string $slug, string $revision): bool;

    /**
     * Publishes a version.
     *
     * `$flush` true (default, standard applications): persists and flushes the bundle's entity manager. Outside a
     * transaction it also deletes the cached map and dispatches {@see LegalVersionPublishedEvent}; inside one, call
     * {@see afterPublish()} once it committed. `$flush` false (the host controls the unit of work, DDD): only
     * persists; flush and commit yourself, then call {@see afterPublish()}.
     *
     * @param array<string, string> $changeSummary Locale => plain-text summary
     * @param ?int $version Version number to publish (a concurrent publish of the same number fails on the unique
     *                      key); null = current + 1
     *
     * @return LegalPageVersionView The new version
     */
    public function publishVersion(string $slug, bool $requiresAcceptance, string $source, array $changeSummary = [], ?string $seedRevision = null, ?int $version = null, bool $flush = true): LegalPageVersionView;

    /**
     * After the publishing transaction committed: deletes the cached map and dispatches one
     * {@see LegalVersionPublishedEvent} per version.
     *
     * @return void Nothing (side effects only)
     */
    public function afterPublish(LegalPageVersionView ...$published): void;

    /**
     * Deletes the cached requirements map.
     *
     * @return void Nothing (side effects only)
     */
    public function invalidate(): void;
}
