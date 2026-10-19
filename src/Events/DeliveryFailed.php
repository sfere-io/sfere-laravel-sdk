<?php

namespace Sfere\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sfere ingestion did not accept these events. `retryable` is true when the failure was a network
 * error, 429 or 5xx that survived every retry (a queued job may still try again).
 */
final class DeliveryFailed
{
    use Dispatchable;

    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public readonly array $messageIds,
        public readonly ?int $status,
        public readonly string $reason,
        public readonly bool $retryable,
    ) {}
}
