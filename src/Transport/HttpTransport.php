<?php

namespace Sfere\Transport;

use Illuminate\Http\Client\Factory as HttpFactory;
use Sfere\Contracts\Transport;
use Sfere\Support\Settings;

/**
 * STUB: implemented by the transport module.
 */
final class HttpTransport implements Transport
{
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpFactory $http,
    ) {}

    public function sendOne(array $event): DeliveryResult
    {
        return DeliveryResult::failure([], null, 'not implemented', false);
    }

    public function sendBatch(array $events): DeliveryResult
    {
        return DeliveryResult::failure([], null, 'not implemented', false);
    }
}
