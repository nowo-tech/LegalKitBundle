<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per recorded acceptance / acknowledgement (re-acceptance page). Carries the subject id: a listener
 * that stores it owns that copy's personal-data lifecycle (export, erasure). Public API (SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalAcceptedEvent extends Event
{
    /**
     * @param string $subjectId Host subject id
     * @param string $slug Page slug
     * @param int $version Version accepted
     * @param string $locale Content locale shown
     * @param string $kind accept|acknowledge
     * @param DateTimeImmutable $acceptedAt Time of the record
     * @param string $contentHash SHA-256 of the offered title and body
     */
    public function __construct(
        public readonly string $subjectId,
        public readonly string $slug,
        public readonly int $version,
        public readonly string $locale,
        public readonly string $kind,
        public readonly DateTimeImmutable $acceptedAt,
        public readonly string $contentHash,
    ) {
    }
}
