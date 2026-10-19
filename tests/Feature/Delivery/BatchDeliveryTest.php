<?php

namespace Sfere\Tests\Feature\Delivery;

use Sfere\Buffer\EventBuffer;
use Sfere\Contracts\Dispatcher;
use Sfere\Delivery\BatchDispatcher;
use Sfere\Delivery\Sender;
use Sfere\Support\Settings;

final class BatchDeliveryTest extends DeliveryTestCase
{
    public function test_batch_mode_buffers_until_flushed(): void
    {
        $this->configure(['delivery' => 'batch']);

        $ids = [$this->track(), $this->track(), $this->track()];

        $this->assertSame([], $this->transport->batches);
        $this->assertInstanceOf(BatchDispatcher::class, $this->app->make(Dispatcher::class));

        $this->client()->flush();

        $this->assertCount(1, $this->transport->batches);
        $this->assertSame($ids, Sender::messageIds($this->transport->batches[0]));
        $this->assertSame([], $this->transport->single);

        $this->client()->flush();
        $this->assertCount(1, $this->transport->batches, 'an empty buffer sends nothing');
    }

    public function test_it_flushes_when_the_buffer_reaches_max_size(): void
    {
        $this->configure(['delivery' => 'batch', 'batch.max_size' => 3]);

        $this->track();
        $this->track();
        $this->assertSame([], $this->transport->batches);

        $this->track();
        $this->assertCount(1, $this->transport->batches);
        $this->assertCount(3, $this->transport->batches[0]);
    }

    public function test_max_size_is_capped_at_100(): void
    {
        $this->configure(['delivery' => 'batch', 'batch.max_size' => 500]);

        for ($i = 0; $i < 100; $i++) {
            $this->track();
        }

        $this->assertCount(1, $this->transport->batches);
        $this->assertCount(100, $this->transport->batches[0]);
    }

    public function test_it_flushes_when_the_oldest_event_waited_max_wait_seconds(): void
    {
        $now = 1000.0;
        $settings = new Settings(batchMaxWaitSeconds: 10);
        $buffer = new EventBuffer($settings, function () use (&$now): float {
            return $now;
        });
        $this->app->instance(EventBuffer::class, $buffer);
        $this->configure(['delivery' => 'batch', 'batch.max_wait_seconds' => 10]);

        $this->track();
        $now += 5;
        $this->track();
        $this->assertSame([], $this->transport->batches);

        $now += 5;
        $this->track();
        $this->assertCount(1, $this->transport->batches);
        $this->assertCount(3, $this->transport->batches[0]);
    }

    public function test_a_flush_splits_on_max_bytes(): void
    {
        $this->configure(['delivery' => 'batch', 'batch.max_bytes' => 5_000]);

        for ($i = 0; $i < 6; $i++) {
            $this->client()->track('view_item', ['blob' => str_repeat('x', 1_500)], userId: 'u-1');
        }
        $this->client()->flush();

        $this->assertGreaterThan(1, count($this->transport->batches));
        $this->assertCount(6, $this->transport->sent());
        foreach ($this->transport->batches as $batch) {
            $bytes = Sender::ENVELOPE_OVERHEAD + array_sum(array_map(fn ($e) => EventBuffer::size($e) + 1, $batch));
            $this->assertLessThanOrEqual(5_000, $bytes);
        }
    }

    public function test_the_buffer_is_empty_even_when_sending_throws(): void
    {
        $this->configure(['delivery' => 'batch', 'throw_exceptions' => true]);
        $this->transport->respond = fn () => throw new \RuntimeException('boom');

        $this->track();
        try {
            $this->client()->flush();
            $this->fail('expected the exception');
        } catch (\RuntimeException) {
        }

        $this->assertTrue($this->app->make(EventBuffer::class)->isEmpty());
    }
}
