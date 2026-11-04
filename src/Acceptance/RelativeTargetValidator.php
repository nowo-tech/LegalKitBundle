<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use function explode;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;

/**
 * Post-accept targets: only same-origin relative paths (no scheme, no "//", no "/\", no control characters, at most
 * 2048 characters), never the re-acceptance page itself.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RelativeTargetValidator
{
    /** Longest target kept. */
    public const int MAX_LENGTH = 2048;

    /**
     * @param string $acceptPath Path suffix of the re-acceptance page (`<routing.path_prefix>/accept`)
     */
    public function __construct(
        private string $acceptPath,
    ) {
    }

    /**
     * Whether the target may be redirected to.
     */
    public function isValid(string $target): bool
    {
        if ($target === '' || strlen($target) > self::MAX_LENGTH || !str_starts_with($target, '/')) {
            return false;
        }
        if (str_starts_with($target, '//') || str_starts_with($target, '/\\') || preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1) {
            return false;
        }
        $path = rtrim(explode('?', explode('#', $target, 2)[0], 2)[0], '/');

        return !str_ends_with($path, rtrim($this->acceptPath, '/'));
    }
}
