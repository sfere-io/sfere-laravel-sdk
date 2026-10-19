<?php

namespace Sfere\Contracts;

/**
 * Decides when built events leave the process: now (sync), at the end of the lifecycle (batch),
 * or through a queued job (queue).
 */
interface Dispatcher
{
    /**
     * @param  array<string, mixed>  $event  a fully built wire event
     */
    public function dispatch(array $event): void;

    /**
     * Send (or enqueue) everything buffered now and empty the buffer.
     */
    public function flush(): void;
}
