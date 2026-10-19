<?php

namespace Sfere\Support;

use Psr\Log\LoggerInterface;

/**
 * Logs a warning at most once per process. The state is deliberately static: it is per process,
 * not per request, so it survives Octane's per-request container reset.
 */
final class WarnOnce
{
    /** @var array<string, true> */
    private static array $warned = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warn(LoggerInterface $logger, string $key, string $message, array $context = []): void
    {
        if (isset(self::$warned[$key])) {
            return;
        }
        self::$warned[$key] = true;
        $logger->warning($message, $context);
    }

    /**
     * For tests.
     */
    public static function reset(): void
    {
        self::$warned = [];
    }
}
