<?php

namespace Sfere\Tests\Feature\Console;

use Sfere\Contracts\Transport;
use Sfere\Transport\DeliveryResult;

/**
 * Records what would be sent and answers with a canned result.
 */
final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function __construct(private readonly DeliveryResult $result) {}

    public function sendOne(array $event): DeliveryResult
    {
        $this->sent[] = $event;

        return $this->result;
    }

    public function sendBatch(array $events): DeliveryResult
    {
        array_push($this->sent, ...$events);

        return $this->result;
    }
}
