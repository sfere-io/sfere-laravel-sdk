<?php

namespace Sfere\Tests\Feature\Delivery\Support;

use Closure;
use Sfere\Contracts\Transport;
use Sfere\Delivery\Sender;
use Sfere\Transport\DeliveryResult;

/**
 * Records what would go on the wire. Answers with `$respond` (default: everything accepted).
 */
final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $single = [];

    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    /**
     * @param  (Closure(list<array<string, mixed>>): DeliveryResult)|null  $respond
     */
    public function __construct(public ?Closure $respond = null) {}

    public function sendOne(array $event): DeliveryResult
    {
        $this->single[] = $event;

        return $this->answer([$event]);
    }

    public function sendBatch(array $events): DeliveryResult
    {
        $this->batches[] = $events;

        return $this->answer($events);
    }

    /**
     * Every event sent, single or batched.
     *
     * @return list<array<string, mixed>>
     */
    public function sent(): array
    {
        return [...$this->single, ...array_merge([], ...$this->batches)];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function answer(array $events): DeliveryResult
    {
        return $this->respond !== null
            ? ($this->respond)($events)
            : DeliveryResult::success(Sender::messageIds($events));
    }
}
