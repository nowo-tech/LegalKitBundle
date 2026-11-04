<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Versioning\Diff;

/**
 * One row of a side-by-side text diff.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DiffRow
{
    public const string EQUAL = 'equal';

    public const string INSERT = 'insert';

    public const string DELETE = 'delete';

    public const string CHANGE = 'change';

    /**
     * @param string $op equal|insert|delete|change
     * @param list<DiffSegment> $left Old side (empty for insert)
     * @param list<DiffSegment> $right New side (empty for delete)
     */
    public function __construct(
        public string $op,
        public array $left,
        public array $right,
    ) {
    }
}
