<?php

namespace Sfere\Buffer;

use Closure;
use Sfere\Support\Settings;

/**
 * In-memory buffer of built wire events, flushed by the batch and queue dispatchers. State lives
 * on the instance only, so a buffer never leaks between Octane requests once it is drained.
 */
final class EventBuffer
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $bytes = 0;

    private ?float $oldestAt = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  current time in seconds (tests pass a fixed one)
     */
    public function __construct(private readonly Settings $settings, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function push(array $event): void
    {
        if ($this->events === []) {
            $this->oldestAt = ($this->clock)();
        }
        $this->events[] = $event;
        $this->bytes += self::size($event);
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * JSON size of the buffered events, without the batch envelope.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * True when the buffer is full or its oldest event has waited `batch.max_wait_seconds`.
     */
    public function shouldFlush(): bool
    {
        if ($this->events === []) {
            return false;
        }
        if (count($this->events) >= $this->settings->batchMaxSize) {
            return true;
        }

        return $this->settings->batchMaxWaitSeconds > 0
            && $this->oldestAt !== null
            && ($this->clock)() - $this->oldestAt >= $this->settings->batchMaxWaitSeconds;
    }

    /**
     * Returns every buffered event and empties the buffer.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];
        $this->bytes = 0;
        $this->oldestAt = null;

        return $events;
    }

    /**
     * Bytes one event adds to a JSON batch body.
     *
     * @param  array<string, mixed>  $event
     */
    public static function size(array $event): int
    {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? 0 : strlen($json);
    }
}
