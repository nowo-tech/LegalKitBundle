<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Security;

/**
 * DEV / DEMO ONLY checker used with `security.allow_unauthenticated: true`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AllowAllLegalKitAccessChecker implements LegalKitAccessCheckerInterface
{
    /**
     * Always grants access.
     */
    public function canAccess(): bool
    {
        return true;
    }
}
