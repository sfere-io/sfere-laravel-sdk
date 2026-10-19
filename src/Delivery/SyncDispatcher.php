<?php

namespace Sfere\Delivery;

use Sfere\Contracts\Dispatcher;

/**
 * STUB: implemented by the delivery module.
 */
final class SyncDispatcher implements Dispatcher
{
    public function __construct(private readonly Sender $sender) {}

    public function dispatch(array $event): void {}

    public function flush(): void {}
}
