<?php

namespace Sfere\Testing;

use Sfere\Contracts\Dispatcher;

/**
 * Keeps every built event in memory instead of sending it. Used by `Sfere::fake()`.
 */
final class RecordingDispatcher implements Dispatcher
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function dispatch(array $event): void
    {
        $this->events[] = $event;
    }

    public function flush(): void {}

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return $this->events;
    }
}
