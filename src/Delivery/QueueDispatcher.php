<?php

namespace Sfere\Delivery;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Sfere\Buffer\EventBuffer;
use Sfere\Contracts\Dispatcher;
use Sfere\Jobs\SendEncryptedEvents;
use Sfere\Jobs\SendEvents;
use Sfere\Support\Settings;

/**
 * `queue`: buffer like `batch`, then dispatch one SendEvents job per batch-sized chunk on the
 * configured connection and queue, encrypted unless `queue.encrypt` is off. Events already carry their context (captured at call time).
 */
final class QueueDispatcher implements Dispatcher
{
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Settings $settings,
        private readonly BusDispatcher $bus,
    ) {}

    public function dispatch(array $event): void
    {
        $this->buffer->push($event);

        if ($this->buffer->shouldFlush()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $events = $this->buffer->drain();

        foreach (Sender::split($events, $this->settings) as $chunk) {
            $job = $this->settings->queueEncrypt
                ? new SendEncryptedEvents($chunk, $this->settings->queueTries, $this->settings->queueBackoff)
                : new SendEvents($chunk, $this->settings->queueTries, $this->settings->queueBackoff);
            $job->onConnection($this->settings->queueConnection)->onQueue($this->settings->queueName);

            $this->bus->dispatch($job);
        }
    }
}
