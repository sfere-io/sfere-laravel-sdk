<?php

namespace Sfere\Delivery;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Sfere\Sfere;

/**
 * Flushes buffered events at the end of every request, queued job and console command, and on
 * Octane's RequestTerminated so a long-lived worker never carries a buffer into the next
 * request. Only a client that was already resolved is flushed: nothing is built just to flush.
 */
final class LifecycleFlusher
{
    /** Listened to by name, so laravel/octane stays optional. */
    public const OCTANE_REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    public static function register(Application $app): void
    {
        $app->terminating(static function () use ($app): void {
            self::flush($app);
        });

        $events = $app->make('events');

        $events->listen([JobProcessed::class, JobFailed::class, CommandFinished::class], static function () use ($app): void {
            self::flush($app);
        });

        $events->listen(self::OCTANE_REQUEST_TERMINATED, static function (object $event) use ($app): void {
            $sandbox = property_exists($event, 'sandbox') ? $event->sandbox : null;
            if ($sandbox instanceof Application && $sandbox !== $app) {
                self::flush($sandbox);
            }
            self::flush($app);
        });
    }

    /**
     * Sfere::flush() swallows and logs delivery errors unless `throw_exceptions` is on.
     */
    public static function flush(Application $app): void
    {
        if ($app->resolved(Sfere::class)) {
            $app->make(Sfere::class)->flush();
        }
    }
}
