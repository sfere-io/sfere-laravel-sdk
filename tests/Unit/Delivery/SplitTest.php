<?php

namespace Sfere\Tests\Unit\Delivery;

use PHPUnit\Framework\TestCase;
use Sfere\Buffer\EventBuffer;
use Sfere\Delivery\Sender;
use Sfere\Support\Settings;

final class SplitTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function events(int $count, int $padding = 0): array
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $events[] = ['messageId' => "m-{$i}", 'properties' => ['pad' => str_repeat('x', $padding)]];
        }

        return $events;
    }

    public function test_it_splits_on_event_count(): void
    {
        $chunks = Sender::split($this->events(250), new Settings(batchMaxSize: 100));

        $this->assertSame([100, 100, 50], array_map('count', $chunks));
        $this->assertSame('m-100', $chunks[1][0]['messageId']);
    }

    public function test_it_splits_on_bytes_including_the_envelope(): void
    {
        $events = $this->events(10, padding: 9_000);
        $size = EventBuffer::size($events[0]) + 1;
        $settings = new Settings(batchMaxBytes: Sender::ENVELOPE_OVERHEAD + 3 * $size);

        $chunks = Sender::split($events, $settings);

        $this->assertSame([3, 3, 3, 1], array_map('count', $chunks));
        foreach ($chunks as $chunk) {
            $bytes = Sender::ENVELOPE_OVERHEAD + array_sum(array_map(fn ($e) => EventBuffer::size($e) + 1, $chunk));
            $this->assertLessThanOrEqual($settings->batchMaxBytes, $bytes);
        }
    }

    public function test_an_oversized_event_travels_alone(): void
    {
        $events = [...$this->events(1), ...$this->events(1, padding: 20_000), ...$this->events(1)];

        $chunks = Sender::split($events, new Settings(batchMaxBytes: 10_000));

        $this->assertSame([1, 1, 1], array_map('count', $chunks));
    }

    public function test_nothing_to_split(): void
    {
        $this->assertSame([], Sender::split([], new Settings));
    }

    public function test_message_ids(): void
    {
        $this->assertSame(['a', 'b'], Sender::messageIds([['messageId' => 'a'], ['x' => 1], ['messageId' => 'b']]));
    }
}
