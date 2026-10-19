<?php

namespace Sfere\Delivery;

use Sfere\Contracts\Dispatcher;

/**
 * Drops every event: used when the SDK is disabled or misconfigured.
 */
final class NullDispatcher implements Dispatcher
{
    public function dispatch(array $event): void {}

    public function flush(): void {}
}
