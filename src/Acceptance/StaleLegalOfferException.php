<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use RuntimeException;

use function sprintf;

/**
 * 1.14 (REQ-RUNTIME-003, acceptances are evidence): an offered page is no longer at the version the offer named (a
 * version was published after the offer was read, or the offer came from a stale cached requirements map). Nothing of
 * the batch is recorded: the subject must be shown the current offer. The re-acceptance page does that itself; a host
 * calling {@see LegalAcceptanceManagerInterface::record()} or {@see LegalAcceptanceManagerInterface::recordInTransaction()}
 * reads {@see LegalAcceptanceManagerInterface::pendingFor()} again.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class StaleLegalOfferException extends RuntimeException
{
    /**
     * @param string $slug Page slug
     * @param int $offeredVersion Version the offer named
     * @param int $currentVersion Version committed in the database
     */
    public function __construct(
        public readonly string $slug,
        public readonly int $offeredVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(sprintf('Legal page "%s" was offered at version %d, but version %d is current: nothing was recorded.', $slug, $offeredVersion, $currentVersion));
    }
}
