<?php

namespace Sfere\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Sfere\Delivery\Sender;
use Sfere\Exceptions\DeliveryException;

/**
 * Sends one batch of built events (at most 100 events / 500 KB, so one `/v1/batch` request).
 *
 * A retryable failure (network error, 429, 5xx after the HTTP retries) fails the attempt, so the
 * queue retries the whole job with `queue.backoff`. That is safe: a retryable failure means the
 * whole request failed, and messageIds are fixed when the job is created, so the warehouse
 * (deduplicating on `message_id`) and the ad platforms (on `event_id`) never count an event
 * twice. Non-retryable failures (4xx, partial batches) complete the job; they are already
 * logged and reported through `DeliveryFailed`.
 */
class SendEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  list<int>  $backoff  seconds between attempts
     */
    public function __construct(
        public readonly array $events,
        public int $tries = 5,
        public array $backoff = [10, 30, 60, 120],
    ) {}

    /**
     * @throws DeliveryException when the failure is retryable
     */
    public function handle(Sender $sender): void
    {
        $result = $sender->deliver($this->events, throw: false);

        if (! $result->ok && $result->retryable) {
            throw new DeliveryException($result);
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->backoff;
    }
}
