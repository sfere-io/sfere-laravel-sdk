<?php

namespace Sfere\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that keeps every record in memory.
 */
final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function messages(string $level): array
    {
        return array_values(array_map(
            fn (array $record): string => $record['message'],
            array_filter($this->records, fn (array $record): bool => $record['level'] === $level),
        ));
    }
}
