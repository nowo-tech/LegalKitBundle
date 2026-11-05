<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Acceptance;

use function explode;
use function preg_match;
use function rawurldecode;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtok;

/**
 * Post-accept targets: only same-origin relative paths (no scheme, no "//", no "/\", no backslash, no control
 * characters, no `.` / `..` segment, at most 2048 characters), never the re-acceptance page itself.
 *
 * The path is checked raw and once percent-decoded: `%2e` / `%2E` dot segments, `%5c` backslashes, `%2f%2f` and
 * encoded control characters are refused as well. Browsers normalise dot segments, so `/..//evil.example` would
 * resolve to `//evil.example`; such targets are refused, never normalised.
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
        if ($this->hasUnsafeSegments(strtok($target, '?#'))) {
            return false;
        }
        $path       = rtrim(explode('?', explode('#', $target, 2)[0], 2)[0], '/');
        $acceptPath = rtrim($this->acceptPath, '/');

        return !str_ends_with($path, $acceptPath) && !str_ends_with(rtrim(rawurldecode($path), '/'), $acceptPath);
    }

    /**
     * Whether a raw path (before `?` / `#`) holds a dot segment (`.`, `..`, or their `%2e` forms, either case) or,
     * raw or once decoded, an empty segment (`//`), a backslash or a control character.
     */
    private function hasUnsafeSegments(string $path): bool
    {
        $decoded = rawurldecode($path);
        foreach ([$path, $decoded] as $candidate) {
            if (str_contains($candidate, '//') || preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate) === 1) {
                return true;
            }
            foreach (explode('/', $candidate) as $segment) {
                if (preg_match('/^(?:\.|%2e){1,2}$/i', $segment) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
