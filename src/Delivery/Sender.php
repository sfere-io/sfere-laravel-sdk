<?php

namespace Sfere\Delivery;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Psr\Log\LoggerInterface;
use Sfere\Contracts\Transport;
use Sfere\Support\Settings;

/**
 * STUB: implemented by the delivery module.
 */
final class Sender
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Settings $settings,
        private readonly EventDispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}
}
