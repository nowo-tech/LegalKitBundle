<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

use Nowo\LegalKitBundle\Security\LegalKitAccessCheckerInterface;

/**
 * Default publisher check: whoever may open the legal admin may publish.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AccessLegalVersionPublisherChecker implements LegalVersionPublisherCheckerInterface
{
    /**
     * @param LegalKitAccessCheckerInterface $access Admin access checker
     */
    public function __construct(
        private LegalKitAccessCheckerInterface $access,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function canPublish(string $slug): bool
    {
        return $this->access->canAccess();
    }
}
