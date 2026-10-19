<?php

namespace Laravel\Octane\Events;

/**
 * Test-only stand-in for laravel/octane's event (same public properties), loaded only when
 * Octane is not installed.
 */
class RequestTerminated
{
    public function __construct(
        public mixed $app,
        public mixed $sandbox,
        public mixed $request,
        public mixed $response,
    ) {}
}
