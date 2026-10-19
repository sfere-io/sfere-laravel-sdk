<?php

namespace Sfere\Tests\Support;

use Sfere\Contracts\Dispatcher;

/**
 * Records dispatched events in memory; counts flushes.
 */
final class ArrayDispatcher implements Dispatcher
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public int $flushes = 0;

    public function dispatch(array $event): void
    {
        $this->events[] = $event;
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        $last = end($this->events);
        if ($last === false) {
            throw new \LogicException('No event was dispatched.');
        }

        return $last;
    }
}
