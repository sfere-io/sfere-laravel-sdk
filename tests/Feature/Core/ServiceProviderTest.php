<?php

namespace Sfere\Tests\Feature\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Sfere\Context\CurrentRequest;
use Sfere\Contracts\Dispatcher;
use Sfere\Contracts\Transport;
use Sfere\Delivery\BatchDispatcher;
use Sfere\Delivery\NullDispatcher;
use Sfere\Delivery\QueueDispatcher;
use Sfere\Delivery\SyncDispatcher;
use Sfere\Facades\Sfere as SfereFacade;
use Sfere\Payload\EventFactory;
use Sfere\Sfere;
use Sfere\SfereServiceProvider;
use Sfere\Support\Settings;
use Sfere\Tests\Support\ArrayDispatcher;
use Sfere\Tests\Support\TestLogger;
use Sfere\Tests\TestCase;
use Sfere\Transport\HttpTransport;

final class ServiceProviderTest extends TestCase
{
    private TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->logger = new TestLogger;
    }

    /**
     * Applies config and rebuilds the SDK singletons, as on a fresh boot.
     *
     * @param  array<string, mixed>  $config
     */
    private function boot(array $config = []): void
    {
        foreach ($config as $key => $value) {
            config()->set("sfere.{$key}", $value);
        }
        foreach ([Settings::class, Dispatcher::class, EventFactory::class, Sfere::class, 'sfere.logger'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        $this->app->instance('sfere.logger', $this->logger);
        SfereFacade::clearResolvedInstances();
    }

    public function test_config_is_merged_and_the_client_is_a_singleton(): void
    {
        $this->assertSame('https://ingest.example.com', config('sfere.host'));
        $this->assertSame(100, config('sfere.batch.max_size'));

        $this->assertSame($this->app->make(Sfere::class), $this->app->make('sfere'));
        $this->assertSame($this->app->make(Sfere::class), SfereFacade::getFacadeRoot());
        $this->assertInstanceOf(HttpTransport::class, $this->app->make(Transport::class));
    }

    public function test_the_config_is_publishable_under_the_sfere_config_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(SfereServiceProvider::class, 'sfere-config');

        $this->assertCount(1, $paths);
        $this->assertSame(realpath(__DIR__.'/../../../config/sfere.php'), realpath((string) array_key_first($paths)));
        $this->assertSame($this->app->configPath('sfere.php'), array_values($paths)[0]);
    }

    public function test_the_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('sfere:test', $commands);
        $this->assertContains('sfere:install', $commands);
    }

    public function test_delivery_mode_selects_the_dispatcher(): void
    {
        $this->boot(['delivery' => 'sync']);
        $this->assertInstanceOf(SyncDispatcher::class, $this->app->make(Dispatcher::class));

        $this->boot(['delivery' => 'queue']);
        $this->assertInstanceOf(QueueDispatcher::class, $this->app->make(Dispatcher::class));

        $this->boot(['delivery' => 'batch']);
        $this->assertInstanceOf(BatchDispatcher::class, $this->app->make(Dispatcher::class));

        $this->boot(['delivery' => 'nonsense']);
        $this->assertInstanceOf(BatchDispatcher::class, $this->app->make(Dispatcher::class));

        $this->assertSame([], $this->logger->records);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function misconfigurations(): array
    {
        return [
            'disabled' => [['enabled' => false], 'disabled (SFERE_ENABLED=false)'],
            'no host' => [['host' => null], 'SFERE_HOST'],
            'host with a path' => [['host' => 'https://ingest.example.com/v1/batch'], 'SFERE_HOST'],
            'no key' => [['write_key' => null], 'SFERE_WRITE_KEY is missing'],
            'browser-style key' => [['write_key' => 'plainkey'], 'SFERE_WRITE_KEY is not a keyId:secret server key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('misconfigurations')]
    public function test_misconfigured_sdk_is_a_no_op_that_warns_once(array $config, string $reason): void
    {
        $this->boot($config);

        $this->assertInstanceOf(NullDispatcher::class, $this->app->make(Dispatcher::class));

        for ($i = 0; $i < 5; $i++) {
            $id = SfereFacade::track('purchase', ['value' => 1], userId: 'u');
            $this->assertNotSame('', $id, 'a messageId is returned even when disabled');
        }
        SfereFacade::flush();

        // A new container (e.g. the next Octane request) still does not warn again in this process.
        $this->boot();
        $this->app->make(Dispatcher::class);

        $warnings = $this->logger->messages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Sfere: events are not sent: ', $warnings[0]);
        $this->assertStringContainsString($reason, $warnings[0]);
        $this->assertStringNotContainsString('5ecret', $warnings[0]);
        Http::assertNothingSent();
    }

    public function test_turning_off_request_context_warns_once(): void
    {
        $this->boot(['context.include_request' => false]);
        $this->app->make(Dispatcher::class);
        $this->boot();
        $this->app->make(Dispatcher::class);

        $warnings = $this->logger->messages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('include_request is off', $warnings[0]);
    }

    public function test_the_framework_version_is_reported_as_the_library_base(): void
    {
        $this->boot(['delivery' => 'sync']);
        $client = $this->app->make(Sfere::class)->withDispatcher($recorder = new ArrayDispatcher);

        $client->track('e', userId: 'u');

        $this->assertSame('laravel/framework@'.$this->app->version(), $recorder->last()['context']['library']['base']);
    }

    public function test_current_request_inside_tests_and_http(): void
    {
        $current = new CurrentRequest($this->app);
        $this->assertInstanceOf(Request::class, $current->get());
    }

    public function test_current_request_is_null_in_console_outside_tests(): void
    {
        $this->app['env'] = 'production';

        $this->assertTrue($this->app->runningInConsole());
        $this->assertNull((new CurrentRequest($this->app))->get());
    }

    public function test_current_request_is_null_when_no_request_is_bound(): void
    {
        unset($this->app['request']);

        $this->assertNull((new CurrentRequest($this->app))->get());
    }
}
