<?php

namespace Sfere\Tests\Feature\Delivery;

use Sfere\Contracts\Transport;
use Sfere\Sfere;
use Sfere\Tests\Feature\Delivery\Support\FakeTransport;
use Sfere\Tests\Feature\Delivery\Support\TestLogger;
use Sfere\Tests\TestCase;

abstract class DeliveryTestCase extends TestCase
{
    protected FakeTransport $transport;

    protected TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport;
        $this->logger = new TestLogger;
        $this->app->instance(Transport::class, $this->transport);
        $this->app->instance('sfere.logger', $this->logger);
    }

    /**
     * Config changes must happen before the client is first resolved.
     *
     * @param  array<string, mixed>  $config
     */
    protected function configure(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->app['config']->set("sfere.{$key}", $value);
        }
    }

    protected function client(): Sfere
    {
        return $this->app->make(Sfere::class);
    }

    protected function track(string $event = 'add_to_cart', string $userId = 'u-1'): string
    {
        return $this->client()->track($event, ['value' => 1.5], userId: $userId);
    }
}
