<?php

namespace Sfere\Delivery;

use Sfere\Buffer\EventBuffer;
use Sfere\Contracts\Dispatcher;

/**
 * `batch`: buffer in memory and send to `/v1/batch` when the buffer is full or old, and at the
 * end of the request, job or command (LifecycleFlusher).
 */
final class BatchDispatcher implements Dispatcher
{
    public function __construct(private readonly Sender $sender, private readonly EventBuffer $buffer) {}

    public function dispatch(array $event): void
    {
        $this->buffer->push($event);

        if ($this->buffer->shouldFlush()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        // Drain first: the buffer is empty even if sending throws.
        $events = $this->buffer->drain();

        $this->sender->deliver($events);
    }
}
