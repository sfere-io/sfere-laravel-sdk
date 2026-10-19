<?php

namespace Sfere\Delivery;

use Sfere\Contracts\Dispatcher;

/**
 * `sync`: one `/api/s/s2s/{type}` request per event, inside the call. Retries block the caller.
 */
final class SyncDispatcher implements Dispatcher
{
    public function __construct(private readonly Sender $sender) {}

    public function dispatch(array $event): void
    {
        $this->sender->deliver([$event], single: true);
    }

    public function flush(): void {}
}
