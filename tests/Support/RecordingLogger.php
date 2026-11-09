<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_string;

/**
 * Keeps every log record (level, message, context) for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string, 2: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}
