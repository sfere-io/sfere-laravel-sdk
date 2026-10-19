<?php

namespace Sfere\Console;

use Illuminate\Console\Command;

/**
 * STUB: implemented by the extras module.
 */
final class InstallCommand extends Command
{
    protected $signature = 'sfere:install';

    protected $description = 'Publish the Sfere config and print the env vars to add';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
