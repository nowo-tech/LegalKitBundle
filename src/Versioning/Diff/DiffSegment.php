<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning\Diff;

/**
 * A run of plain text in a diff row. Plain text only: templates print it autoescaped, never with |raw.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DiffSegment
{
    /**
     * @param string $text Plain text (entities already decoded: it must be escaped on output)
     * @param bool $changed Whether this run differs from the other side
     */
    public function __construct(
        public string $text,
        public bool $changed = false,
    ) {
    }
}
