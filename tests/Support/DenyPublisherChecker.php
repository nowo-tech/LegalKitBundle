<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Versioning\LegalVersionPublisherCheckerInterface;

/**
 * Host publisher check of the test kernel (versioning.publisher_checker: test.legal_kit.deny_publish): nobody publishes.
 */
final class DenyPublisherChecker implements LegalVersionPublisherCheckerInterface
{
    public function canPublish(string $slug): bool
    {
        return false;
    }
}
