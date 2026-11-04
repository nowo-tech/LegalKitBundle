<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a new page version was published (editor or nowo:legal-kit:upgrade-seed). Public API (SemVer).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LegalVersionPublishedEvent extends Event
{
    /**
     * @param string $slug Page slug
     * @param int $version New version number
     * @param bool $requiresAcceptance Users must accept / acknowledge it
     * @param string $source editor|upgrade
     * @param ?string $seedRevision Seed revision (upgrade-seed), null otherwise
     */
    public function __construct(
        public readonly string $slug,
        public readonly int $version,
        public readonly bool $requiresAcceptance,
        public readonly string $source,
        public readonly ?string $seedRevision = null,
    ) {
    }
}
