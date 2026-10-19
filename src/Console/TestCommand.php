<?php

namespace Sfere\Console;

use Illuminate\Console\Command;

/**
 * STUB: implemented by the extras module.
 */
final class TestCommand extends Command
{
    protected $signature = 'sfere:test';

    protected $description = 'Send a test event to Sfere ingestion and report the result';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
