<?php

namespace Sfere\Tests\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Sfere\Payload\EventFactory;
use Sfere\Sfere;
use Sfere\Support\Resolvers;
use Sfere\Support\Settings;
use Sfere\Tests\TestCase;

/**
 * Builds clients from config with an in-memory dispatcher, a test logger and no HTTP request,
 * so assertions only depend on the core (not on request context or delivery).
 */
abstract class CoreTestCase extends TestCase
{
    public const FRAMEWORK_VERSION = '13.0.0';

    protected ArrayDispatcher $dispatcher;

    protected TestLogger $logger;

    protected Resolvers $resolvers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new ArrayDispatcher;
        $this->logger = new TestLogger;
        $this->resolvers = new Resolvers;
        Carbon::setTestNow(Carbon::parse('2026-09-29T10:00:00.123Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config  `sfere.*` overrides (dot keys)
     */
    protected function settings(array $config = []): Settings
    {
        foreach ($config as $key => $value) {
            config()->set("sfere.{$key}", $value);
        }

        /** @var array<string, mixed> $sfere */
        $sfere = config('sfere');

        return Settings::fromConfig($sfere);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  (Closure(): DateTimeInterface)|null  $clock
     */
    protected function factory(array $config = [], ?Request $request = null, ?Closure $clock = null): EventFactory
    {
        return new EventFactory($this->settings($config), new FixedCurrentRequest($request), self::FRAMEWORK_VERSION, $clock);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function client(array $config = [], ?Request $request = null): Sfere
    {
        $settings = $this->settings($config);

        return new Sfere(
            $settings,
            new EventFactory($settings, new FixedCurrentRequest($request), self::FRAMEWORK_VERSION),
            $this->dispatcher,
            $this->resolvers,
            $this->logger,
        );
    }
}
