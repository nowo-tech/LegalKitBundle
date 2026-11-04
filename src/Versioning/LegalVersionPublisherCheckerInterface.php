<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning;

/**
 * Who may publish a new version, and save a minor correction of a page users accept or acknowledge
 * (`versioning.publisher_checker`). The default delegates to the admin access checker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LegalVersionPublisherCheckerInterface
{
    /**
     * Whether the current user may choose minor correction / new version for the page.
     *
     * @return bool The answer
     */
    public function canPublish(string $slug): bool;
}
