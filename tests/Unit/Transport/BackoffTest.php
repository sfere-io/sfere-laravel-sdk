<?php

namespace Sfere\Tests\Unit\Transport;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Sfere\Transport\Backoff;

final class BackoffTest extends TestCase
{
    public function test_full_jitter_stays_within_the_exponential_ceiling(): void
    {
        $ceilings = [];
        $backoff = new Backoff(10, function (int $min, int $max) use (&$ceilings): int {
            $ceilings[] = [$min, $max];

            return $max;
        });

        foreach ([1, 2, 3, 4, 5, 6] as $attempt) {
            $backoff->delayMs($attempt);
        }

        $this->assertSame([[0, 500], [0, 1000], [0, 2000], [0, 4000], [0, 8000], [0, 8000]], $ceilings);
    }

    public function test_default_jitter_is_random_and_bounded(): void
    {
        $backoff = new Backoff(10);
        for ($i = 0; $i < 50; $i++) {
            $delay = $backoff->delayMs(2);
            $this->assertGreaterThanOrEqual(0, $delay);
            $this->assertLessThanOrEqual(1000, $delay);
        }
    }

    public function test_retry_after_seconds_wins_and_is_capped(): void
    {
        $backoff = new Backoff(10, fn (): int => 0);

        $this->assertSame(2000, $backoff->delayMs(1, '2'));
        $this->assertSame(0, $backoff->delayMs(1, '0'));
        $this->assertSame(10_000, $backoff->delayMs(1, '3600'));
        $this->assertSame(0, (new Backoff(0, fn (): int => 0))->delayMs(1, '5'));
    }

    public function test_retry_after_http_date(): void
    {
        $now = new DateTimeImmutable('2026-10-19 10:00:00 UTC');
        $backoff = new Backoff(10, fn (): int => 0, fn () => $now);

        $this->assertSame(4000, $backoff->retryAfterMs('Mon, 19 Oct 2026 10:00:04 GMT'));
        $this->assertSame(0, $backoff->retryAfterMs('Mon, 19 Oct 2026 09:59:00 GMT'));
        $this->assertSame(10_000, $backoff->delayMs(1, 'Mon, 19 Oct 2026 11:00:00 GMT'));
    }

    public function test_invalid_retry_after_falls_back_to_jitter(): void
    {
        $backoff = new Backoff(10, fn (int $min, int $max): int => $max);

        $this->assertNull($backoff->retryAfterMs('soon'));
        $this->assertNull($backoff->retryAfterMs(''));
        $this->assertNull($backoff->retryAfterMs(null));
        $this->assertSame(500, $backoff->delayMs(1, 'soon'));
    }
}
