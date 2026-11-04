<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after nowo:legal-kit:upgrade-seed committed (never on --dry-run). Public API (SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalSeedUpgradedEvent extends Event
{
    /**
     * @param string $revision Current seed revision
     * @param ?string $from The --from revision, if any
     * @param array<string, int> $counts Result => number of page / locale pairs
     */
    public function __construct(
        public readonly string $revision,
        public readonly ?string $from,
        public readonly array $counts,
    ) {
    }
}
