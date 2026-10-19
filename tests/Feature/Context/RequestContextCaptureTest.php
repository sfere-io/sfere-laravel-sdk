<?php

namespace Sfere\Tests\Feature\Context;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Sfere\Contracts\Dispatcher;
use Sfere\Facades\Sfere;
use Sfere\Payload\EventFactory;
use Sfere\Support\Settings;
use Sfere\Tests\TestCase;

final class RequestContextCaptureTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatched = [];
        $recorder = new class($this->dispatched) implements Dispatcher
        {
            /** @param list<array<string, mixed>> $events */
            public function __construct(private array &$events) {}

            public function dispatch(array $event): void
            {
                $this->events[] = $event;
            }

            public function flush(): void {}
        };
        $this->app->instance(Dispatcher::class, $recorder);
        $this->app->forgetInstance(\Sfere\Sfere::class);
        Sfere::clearResolvedInstances();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->middleware(EncryptCookies::class)->get('/checkout', function (Request $request) {
            return response()->json([
                'bag_fbp' => $request->cookie('_fbp'),
                'context' => Sfere::requestContext(),
                'messageId' => Sfere::track('purchase', ['transaction_id' => 'o-1', 'currency' => 'SAR', 'value' => 10.0], userId: '10293'),
            ]);
        });
    }

    public function test_cookies_are_read_from_the_raw_header_while_encrypt_cookies_is_active(): void
    {
        $response = $this->call('GET', '/checkout?utm_source=newsletter', [], [
            // What PHP would also put in $_COOKIE: EncryptCookies cannot decrypt these and nulls them.
            '_fbp' => 'fb.1.1700000000000.333',
            '_gcl_aw' => 'GCL.1700000000.CjwKraw',
            'ajs_anonymous_id' => '"anon-77"',
        ], [], [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50, 10.0.0.2',
            'HTTP_USER_AGENT' => 'UA/9',
            'HTTP_COOKIE' => '_fbp=fb.1.1700000000000.333; _gcl_aw=GCL.1700000000.CjwKraw; ajs_anonymous_id=%22anon-77%22',
        ]);

        $response->assertOk();
        $this->assertNull($response->json('bag_fbp'), 'EncryptCookies should have nulled the unencrypted cookie');
        $this->assertSame('203.0.113.50', $response->json('context.ip'));
        $this->assertSame('UA/9', $response->json('context.userAgent'));
        $this->assertSame(['fbp' => 'fb.1.1700000000000.333', 'gclid' => 'CjwKraw'], $response->json('context.clientIds'));
        $this->assertSame(['source' => 'newsletter'], $response->json('context.campaign'));

        $this->assertCount(1, $this->dispatched);
        $event = $this->dispatched[0];
        $this->assertSame($response->json('messageId'), $event['messageId']);
        $this->assertSame('10293', $event['userId']);
        $this->assertSame('anon-77', $event['anonymousId']);
        $this->assertSame('203.0.113.50', $event['context']['ip']);
        $this->assertSame('UA/9', $event['context']['userAgent']);
        $this->assertSame(['fbp' => 'fb.1.1700000000000.333', 'gclid' => 'CjwKraw'], $event['context']['clientIds']);
        $this->assertSame('/checkout', $event['context']['page']['path']);
    }

    public function test_include_request_false_leaves_the_request_context_out(): void
    {
        $this->app['config']->set('sfere.context.include_request', false);
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(EventFactory::class);

        $this->call('GET', '/checkout', [], [], [], [
            'HTTP_USER_AGENT' => 'UA/9',
            'HTTP_COOKIE' => '_fbp=fb.1.1.1; ajs_anonymous_id=anon-88',
        ])->assertOk();

        $event = $this->dispatched[0];
        $this->assertArrayNotHasKey('ip', $event['context']);
        $this->assertArrayNotHasKey('clientIds', $event['context']);
        $this->assertSame('anon-88', $event['anonymousId'], 'the anonymous id cookie is identity, not context');
    }
}
