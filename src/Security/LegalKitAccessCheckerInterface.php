<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Security;

/**
 * Access to the legal pages admin (REQ-UI-002); hosts plug their own with `security.access_checker`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalKitAccessCheckerInterface
{
    /**
     * Whether the current user may use the admin pages.
     */
    public function canAccess(): bool;
}
