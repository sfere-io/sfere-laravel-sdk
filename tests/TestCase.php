<?php

namespace Sfere\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sfere\Facades\Sfere;
use Sfere\SfereServiceProvider;
use Sfere\Support\WarnOnce;

abstract class TestCase extends Orchestra
{
    public const HOST = 'https://ingest.example.com';

    public const WRITE_KEY = 'k3yid0000001:5ecret5ecret5ecret';

    protected function setUp(): void
    {
        parent::setUp();
        WarnOnce::reset();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SfereServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Sfere' => Sfere::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sfere.host', self::HOST);
        $app['config']->set('sfere.write_key', self::WRITE_KEY);
        $app['config']->set('sfere.delivery', 'sync');
    }
}
