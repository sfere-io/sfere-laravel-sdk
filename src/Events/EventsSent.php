<?php

namespace Sfere\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sfere ingestion accepted these events.
 */
final class EventsSent
{
    use Dispatchable;

    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public readonly array $messageIds,
        public readonly int $okEvents,
    ) {}
}
