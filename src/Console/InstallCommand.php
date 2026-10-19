<?php

namespace Sfere\Console;

use Illuminate\Console\Command;

/**
 * Publishes `config/sfere.php` and prints the env vars to add.
 */
final class InstallCommand extends Command
{
    protected $signature = 'sfere:install {--force : Overwrite an existing config/sfere.php}';

    protected $description = 'Publish the Sfere config and print the env vars to add';

    public function handle(): int
    {
        $this->call('vendor:publish', array_filter([
            '--tag' => 'sfere-config',
            '--force' => (bool) $this->option('force'),
        ]));

        $this->newLine();
        $this->info('Add these to your .env:');
        $this->newLine();
        $this->line('SFERE_HOST=https://your-ingestion-host        # origin only, no path');
        $this->line('SFERE_WRITE_KEY=keyId:secret                  # a SERVER key');
        $this->line('# SFERE_DELIVERY=batch                        # sync | batch | queue');
        $this->newLine();
        $this->warn('Use a SERVER write key. A browser key is accepted too, but then every event reports your server\'s IP instead of your customer\'s. Keep the key out of your frontend and your repository.');
        $this->newLine();
        $this->line('Then run: php artisan sfere:test');

        return self::SUCCESS;
    }
}
