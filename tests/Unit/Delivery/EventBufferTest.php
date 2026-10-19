<?php

namespace Sfere\Tests\Unit\Delivery;

use PHPUnit\Framework\TestCase;
use Sfere\Buffer\EventBuffer;
use Sfere\Support\Settings;

final class EventBufferTest extends TestCase
{
    private float $now = 1000.0;

    private function buffer(int $maxSize = 100, int $maxWait = 10): EventBuffer
    {
        return new EventBuffer(new Settings(batchMaxSize: $maxSize, batchMaxWaitSeconds: $maxWait), fn (): float => $this->now);
    }

    public function test_it_counts_measures_and_drains(): void
    {
        $buffer = $this->buffer();
        $this->assertTrue($buffer->isEmpty());

        $buffer->push(['messageId' => 'a']);
        $buffer->push(['messageId' => 'b']);

        $this->assertSame(2, $buffer->count());
        $this->assertSame(strlen('{"messageId":"a"}') * 2, $buffer->bytes());
        $this->assertSame([['messageId' => 'a'], ['messageId' => 'b']], $buffer->drain());
        $this->assertTrue($buffer->isEmpty());
        $this->assertSame(0, $buffer->bytes());
        $this->assertSame([], $buffer->drain());
    }

    public function test_it_wants_a_flush_when_full(): void
    {
        $buffer = $this->buffer(maxSize: 2);
        $buffer->push(['messageId' => 'a']);
        $this->assertFalse($buffer->shouldFlush());
        $buffer->push(['messageId' => 'b']);
        $this->assertTrue($buffer->shouldFlush());
    }

    public function test_it_wants_a_flush_when_the_oldest_event_is_old(): void
    {
        $buffer = $this->buffer(maxWait: 10);
        $buffer->push(['messageId' => 'a']);
        $this->now += 9.9;
        $this->assertFalse($buffer->shouldFlush());
        $this->now += 0.1;
        $this->assertTrue($buffer->shouldFlush());
    }

    public function test_age_restarts_after_a_drain(): void
    {
        $buffer = $this->buffer(maxWait: 10);
        $buffer->push(['messageId' => 'a']);
        $this->now += 20;
        $buffer->drain();
        $buffer->push(['messageId' => 'b']);
        $this->assertFalse($buffer->shouldFlush());
    }

    public function test_zero_max_wait_disables_the_age_check(): void
    {
        $buffer = $this->buffer(maxWait: 0);
        $buffer->push(['messageId' => 'a']);
        $this->now += 3600;
        $this->assertFalse($buffer->shouldFlush());
    }

    public function test_an_empty_buffer_never_wants_a_flush(): void
    {
        $this->assertFalse($this->buffer(maxSize: 1, maxWait: 0)->shouldFlush());
    }
}
