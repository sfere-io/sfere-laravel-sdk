<?php

namespace Sfere\Tests\Feature\Console;

use Sfere\Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(config_path('sfere.php'));

        parent::tearDown();
    }

    public function test_it_publishes_the_config_and_prints_the_env_vars(): void
    {
        @unlink(config_path('sfere.php'));

        $this->artisan('sfere:install')
            ->expectsOutputToContain('SFERE_HOST=')
            ->expectsOutputToContain('SFERE_WRITE_KEY=')
            ->expectsOutputToContain('SERVER write key')
            ->assertSuccessful();

        $this->assertFileExists(config_path('sfere.php'));
        $this->assertFileEquals(__DIR__.'/../../../config/sfere.php', config_path('sfere.php'));
    }
}
