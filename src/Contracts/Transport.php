<?php

namespace Sfere\Contracts;

use Sfere\Transport\DeliveryResult;

/**
 * Sends fully built wire events to Sfere ingestion, retrying network errors, 429 and 5xx inside
 * the call. Never throws: every outcome is a DeliveryResult.
 */
interface Transport
{
    /**
     * One event to `POST {host}/api/s/s2s/{type}`.
     *
     * @param  array<string, mixed>  $event
     */
    public function sendOne(array $event): DeliveryResult;

    /**
     * Up to 100 events (500 KB) to `POST {host}/v1/batch`. The caller splits larger sets.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function sendBatch(array $events): DeliveryResult;
}
