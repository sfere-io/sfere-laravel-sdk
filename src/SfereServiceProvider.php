<?php

namespace Sfere;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Sfere\Buffer\EventBuffer;
use Sfere\Console\InstallCommand;
use Sfere\Console\TestCommand;
use Sfere\Context\CurrentRequest;
use Sfere\Contracts\Dispatcher;
use Sfere\Contracts\Transport;
use Sfere\Delivery\BatchDispatcher;
use Sfere\Delivery\LifecycleFlusher;
use Sfere\Delivery\NullDispatcher;
use Sfere\Delivery\QueueDispatcher;
use Sfere\Delivery\Sender;
use Sfere\Delivery\SyncDispatcher;
use Sfere\Payload\EventFactory;
use Sfere\Support\Resolvers;
use Sfere\Support\Settings;
use Sfere\Support\WarnOnce;
use Sfere\Transport\HttpTransport;

class SfereServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sfere.php', 'sfere');

        $this->app->singleton(Settings::class, function (Application $app): Settings {
            /** @var array<string, mixed> $config */
            $config = (array) $app->make('config')->get('sfere', []);

            return Settings::fromConfig($config);
        });

        $this->app->singleton(Resolvers::class);

        $this->app->singleton('sfere.logger', function (Application $app): LoggerInterface {
            return $app->make('log')->channel($app->make(Settings::class)->logChannel);
        });

        $this->app->singleton(CurrentRequest::class, fn (Application $app) => new CurrentRequest($app));

        $this->app->singleton(Transport::class, fn (Application $app) => new HttpTransport(
            $app->make(Settings::class),
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(Sender::class, fn (Application $app) => new Sender(
            $app->make(Transport::class),
            $app->make(Settings::class),
            $app->make(EventDispatcher::class),
            $app->make('sfere.logger'),
        ));

        $this->app->singleton(EventBuffer::class, fn (Application $app) => new EventBuffer($app->make(Settings::class)));

        $this->app->singleton(Dispatcher::class, fn (Application $app) => $this->makeDispatcher($app));

        $this->app->singleton(EventFactory::class, fn (Application $app) => new EventFactory(
            $app->make(Settings::class),
            $app->make(CurrentRequest::class),
            $app->version(),
        ));

        $this->app->singleton(Sfere::class, fn (Application $app) => new Sfere(
            $app->make(Settings::class),
            $app->make(EventFactory::class),
            $app->make(Dispatcher::class),
            $app->make(Resolvers::class),
            $app->make('sfere.logger'),
        ));
        $this->app->alias(Sfere::class, 'sfere');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sfere.php' => $this->app->configPath('sfere.php'),
            ], 'sfere-config');

            $this->commands([
                TestCommand::class,
                InstallCommand::class,
            ]);
        }

        LifecycleFlusher::register($this->app);
    }

    private function makeDispatcher(Application $app): Dispatcher
    {
        $settings = $app->make(Settings::class);
        /** @var LoggerInterface $logger */
        $logger = $app->make('sfere.logger');

        if (! $settings->isOperational()) {
            WarnOnce::warn($logger, 'sfere.noop', 'Sfere: events are not sent: '.implode('; ', $settings->problems()).'.');

            return new NullDispatcher;
        }

        if (! $settings->includeRequest) {
            WarnOnce::warn($logger, 'sfere.no-request-context', 'Sfere: context.include_request is off, so server events reach ad destinations without the customer\'s IP, user agent or click IDs.');
        }

        return match ($settings->delivery) {
            'sync' => new SyncDispatcher($app->make(Sender::class)),
            'queue' => new QueueDispatcher($app->make(EventBuffer::class), $settings, $app->make(BusDispatcher::class)),
            default => new BatchDispatcher($app->make(Sender::class), $app->make(EventBuffer::class)),
        };
    }
}
