<?php

namespace Sfere\Tests\Feature\Delivery\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function at(string $level): array
    {
        return array_values(array_filter($this->records, fn (array $r): bool => $r['level'] === $level));
    }
}
