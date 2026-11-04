<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning\Diff;

/**
 * Result of a text diff: the rows, or `tooLarge` when a limit was hit (show the rendered side by side instead).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TextDiff
{
    /**
     * @param list<DiffRow> $rows Rows (empty when too large)
     * @param bool $tooLarge A size or edit-distance limit was hit
     */
    public function __construct(
        public array $rows,
        public bool $tooLarge = false,
    ) {
    }

    /**
     * Whether both sides are equal.
     */
    public function isEqual(): bool
    {
        if ($this->tooLarge) {
            return false;
        }
        foreach ($this->rows as $row) {
            if ($row->op !== DiffRow::EQUAL) {
                return false;
            }
        }

        return true;
    }
}
