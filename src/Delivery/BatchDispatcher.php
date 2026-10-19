<?php

namespace Sfere\Delivery;

use Sfere\Buffer\EventBuffer;
use Sfere\Contracts\Dispatcher;

/**
 * STUB: implemented by the delivery module.
 */
final class BatchDispatcher implements Dispatcher
{
    public function __construct(private readonly Sender $sender, private readonly EventBuffer $buffer) {}

    public function dispatch(array $event): void {}

    public function flush(): void {}
}
