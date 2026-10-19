<?php

namespace Sfere\Delivery;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Sfere\Buffer\EventBuffer;
use Sfere\Contracts\Dispatcher;
use Sfere\Support\Settings;

/**
 * STUB: implemented by the delivery module.
 */
final class QueueDispatcher implements Dispatcher
{
    public function __construct(
        private readonly EventBuffer $buffer,
        private readonly Settings $settings,
        private readonly BusDispatcher $bus,
    ) {}

    public function dispatch(array $event): void {}

    public function flush(): void {}
}
