<?php

namespace Sfere\Tests\Feature\Console;

use Sfere\Contracts\Transport;
use Sfere\Support\Settings;
use Sfere\Tests\TestCase;
use Sfere\Transport\DeliveryResult;

final class TestCommandTest extends TestCase
{
    public function test_it_sends_a_test_event_and_reports_success(): void
    {
        $transport = $this->bindTransport(DeliveryResult::success(['x'], 200));

        $this->artisan('sfere:test')
            ->expectsOutputToContain('Event delivered')
            ->expectsOutputToContain('SERVER key')
            ->doesntExpectOutputToContain('5ecret5ecret5ecret')
            ->assertSuccessful();

        $this->assertCount(1, $transport->sent);
        $event = $transport->sent[0];
        $this->assertSame('track', $event['type']);
        $this->assertSame('sfere_sdk_test', $event['event']);
        $this->assertSame('sfere:test', $event['properties']['source']);
        $this->assertStringStartsWith('sfere-test-', $event['anonymousId']);
    }

    public function test_it_shows_only_the_key_id(): void
    {
        $this->bindTransport(DeliveryResult::success(['x'], 200));

        $this->artisan('sfere:test')
            ->expectsOutputToContain('k3yid0000001:********')
            ->assertSuccessful();
    }

    public function test_it_reports_a_failure(): void
    {
        $this->bindTransport(DeliveryResult::failure(['x'], 401, 'invalid write key', false));

        $this->artisan('sfere:test')
            ->expectsOutputToContain('invalid write key (HTTP 401, 1 attempt(s))')
            ->assertFailed();
    }

    public function test_it_reports_a_retryable_failure(): void
    {
        $this->bindTransport(DeliveryResult::failure(['x'], 503, 'server error', true, 3));

        $this->artisan('sfere:test')
            ->expectsOutputToContain('3 attempt(s), retryable')
            ->assertFailed();
    }

    public function test_it_does_not_send_when_misconfigured(): void
    {
        $this->configure('sfere.host', 'https://ingest.example.com/api/s');
        $transport = $this->bindTransport(DeliveryResult::success(['x'], 200));

        $this->artisan('sfere:test')
            ->expectsOutputToContain('SFERE_HOST')
            ->assertFailed();

        $this->assertSame([], $transport->sent);
    }

    public function test_it_rejects_a_key_without_a_secret(): void
    {
        $this->configure('sfere.write_key', 'plaintextkey');
        $transport = $this->bindTransport(DeliveryResult::success(['x'], 200));

        $this->artisan('sfere:test')
            ->expectsOutputToContain('not a keyId:secret')
            ->assertFailed();

        $this->assertSame([], $transport->sent);
    }

    private function configure(string $key, mixed $value): void
    {
        config([$key => $value]);
        $this->app->forgetInstance(Settings::class);
    }

    private function bindTransport(DeliveryResult $result): FakeTransport
    {
        $transport = new FakeTransport($result);
        $this->app->instance(Transport::class, $transport);

        return $transport;
    }
}
