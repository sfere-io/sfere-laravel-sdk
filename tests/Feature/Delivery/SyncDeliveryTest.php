<?php

namespace Sfere\Tests\Feature\Delivery;

final class SyncDeliveryTest extends DeliveryTestCase
{
    public function test_sync_sends_each_event_immediately_on_the_single_path(): void
    {
        $this->configure(['delivery' => 'sync']);

        $id = $this->track();

        $this->assertCount(1, $this->transport->single);
        $this->assertSame($id, $this->transport->single[0]['messageId']);
        $this->assertSame([], $this->transport->batches);

        $this->track();
        $this->assertCount(2, $this->transport->single);
    }
}
