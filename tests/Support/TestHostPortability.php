<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Nowo\LegalKitBundle\Portability\LegalDocumentPortability;
use Nowo\LegalKitBundle\Portability\LegalDocumentPortabilityInterface;

use function count;

/**
 * Host decorator of the import / export port: counts the rows it was asked to write, delegates the work.
 */
final class TestHostPortability implements LegalDocumentPortabilityInterface
{
    public int $applied = 0;

    public function __construct(
        private readonly LegalDocumentPortability $inner,
    ) {
    }

    public function export(): array
    {
        return $this->inner->export();
    }

    public function normalize(mixed $payload): array
    {
        return $this->inner->normalize($payload);
    }

    public function apply(array $rows, ?object $actor = null, bool $flush = false): int
    {
        $this->applied += count($rows);

        return $this->inner->apply($rows, $actor, $flush);
    }

    public function afterCommit(array $rows): void
    {
        $this->inner->afterCommit($rows);
    }
}
