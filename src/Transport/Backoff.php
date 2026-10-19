<?php

namespace Sfere\Transport;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Delay before a retry: exponential backoff with full jitter, or the server's `Retry-After`
 * (seconds or an HTTP date) when present, capped at `max_retry_after`.
 */
final class Backoff
{
    public const BASE_MS = 500;

    public const CAP_MS = 8_000;

    /**
     * @param  (Closure(int, int): int)|null  $random  random_int-compatible, for tests
     * @param  (Closure(): DateTimeInterface)|null  $now
     */
    public function __construct(
        private readonly int $maxRetryAfterSeconds,
        private readonly ?Closure $random = null,
        private readonly ?Closure $now = null,
    ) {}

    /**
     * Milliseconds to wait before the next attempt.
     *
     * @param  int  $attempt  the attempt that just failed, starting at 1
     */
    public function delayMs(int $attempt, ?string $retryAfter = null): int
    {
        $fromHeader = $this->retryAfterMs($retryAfter);
        if ($fromHeader !== null) {
            return min($fromHeader, $this->maxRetryAfterSeconds * 1000);
        }

        $ceiling = (int) min(self::CAP_MS, self::BASE_MS * (2 ** max(0, $attempt - 1)));

        return $this->random !== null ? ($this->random)(0, $ceiling) : random_int(0, $ceiling);
    }

    /**
     * `Retry-After: 3` or `Retry-After: Wed, 21 Oct 2026 07:28:00 GMT`, in milliseconds (>= 0).
     */
    public function retryAfterMs(?string $value): ?int
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) min((int) $value, PHP_INT_MAX / 1000) * 1000;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $value)
            ?: DateTimeImmutable::createFromFormat(DateTimeInterface::RFC2822, $value);
        if ($date === false) {
            return null;
        }

        $now = $this->now !== null ? ($this->now)() : Carbon::now();
        $ms = ($date->getTimestamp() - $now->getTimestamp()) * 1000;

        return max(0, $ms);
    }
}
