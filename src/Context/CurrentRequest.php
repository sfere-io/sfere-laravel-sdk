<?php

namespace Sfere\Context;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The customer's HTTP request, when the code runs inside one. Console commands and queue workers
 * have none (their bound request is a synthetic one). Octane workers report
 * `runningInConsole() === false`, so they count as HTTP.
 */
class CurrentRequest
{
    public function __construct(private readonly Application $app) {}

    public function get(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return null;
        }

        return $this->app->make('request');
    }
}
