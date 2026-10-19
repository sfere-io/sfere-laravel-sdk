<?php

namespace Sfere\Tests\Feature\Testing;

use Illuminate\Foundation\Auth\User;
use PHPUnit\Framework\AssertionFailedError;
use Sfere\Ecommerce\Event;
use Sfere\Facades\Sfere;
use Sfere\Sfere as SfereClient;
use Sfere\Support\Library;
use Sfere\Support\Settings;
use Sfere\Testing\SfereFake;
use Sfere\Tests\TestCase;

final class SfereFakeTest extends TestCase
{
    public function test_it_records_tracked_events_and_returns_the_message_id(): void
    {
        $fake = Sfere::fake();

        $messageId = Sfere::track(Event::PURCHASE, ['value' => 256.96, 'currency' => 'SAR'], userId: '10293');

        Sfere::assertTracked(Event::PURCHASE);
        Sfere::assertTracked(Event::PURCHASE, fn (array $event) => $event['properties']['value'] === 256.96);
        Sfere::assertNotTracked(Event::REFUND);
        Sfere::assertSentCount(1);

        $event = $fake->recorded()[0];
        $this->assertSame($messageId, $event['messageId']);
        $this->assertSame('10293', $event['userId']);
        $this->assertSame($messageId, $event['properties']['sfere_dedup_id']);
        $this->assertSame(Library::NAME, $event['context']['library']['name']);
    }

    public function test_it_records_identify_and_page_events(): void
    {
        Sfere::fake();

        Sfere::identify('10293', ['email' => 'fan@example.com']);
        Sfere::page('Pricing', ['url' => 'https://example.com/pricing'], userId: '10293');

        Sfere::assertIdentified('10293');
        Sfere::assertIdentified('10293', fn (array $event) => $event['traits']['email'] === 'fan@example.com');
        Sfere::assertPaged('Pricing');
        Sfere::assertPaged();
        Sfere::assertSentCount(2);
    }

    public function test_nothing_sent_and_invalid_events_are_not_recorded(): void
    {
        Sfere::fake();
        Sfere::assertNothingSent();

        // No userId and no anonymousId: dropped and logged, not recorded.
        Sfere::track(Event::PURCHASE);

        Sfere::assertNothingSent();
    }

    public function test_failing_assertions_fail(): void
    {
        Sfere::fake();
        Sfere::track(Event::REFUND, ['value' => 10], userId: '10293');

        $this->assertAssertionFails(fn () => Sfere::assertTracked(Event::PURCHASE));
        $this->assertAssertionFails(fn () => Sfere::assertTracked(Event::REFUND, fn (array $e) => $e['properties']['value'] === 99));
        $this->assertAssertionFails(fn () => Sfere::assertNotTracked(Event::REFUND));
        $this->assertAssertionFails(fn () => Sfere::assertIdentified('10293'));
        $this->assertAssertionFails(fn () => Sfere::assertNothingSent());
        $this->assertAssertionFails(fn () => Sfere::assertSentCount(2));
        $this->assertAssertionFails(fn () => Sfere::assertPaged());
    }

    public function test_type_hinted_dependencies_receive_the_fake(): void
    {
        $fake = Sfere::fake();

        $client = $this->app->make(SfereClient::class);
        $this->assertSame($fake, $client);
        $this->assertSame($fake, $this->app->make('sfere'));

        $this->app->call(fn (SfereClient $sfere) => $sfere->track('signed', userId: '7'));

        Sfere::assertTracked('signed');
    }

    public function test_scoped_clients_record_into_the_same_fake(): void
    {
        $fake = Sfere::fake();
        $user = new User;
        $user->forceFill(['id' => 42]);

        $scoped = Sfere::forUser($user);
        $this->assertInstanceOf(SfereFake::class, $scoped);
        $scoped->track(Event::SIGN_UP, ['method' => 'email']);
        Sfere::withContext(['campaign' => ['source' => 'newsletter']])->track(Event::LOGIN, userId: '42');
        Sfere::anonymous('anon-1')->track(Event::VIEW_CART);
        Sfere::event('track')->name(Event::ADD_TO_CART)->userId('42')->properties(['value' => 1])->send();

        $this->assertCount(4, $fake->recorded());
        Sfere::assertTracked(Event::SIGN_UP, fn (array $e) => $e['userId'] === '42');
        Sfere::assertTracked(Event::LOGIN, fn (array $e) => $e['context']['campaign']['source'] === 'newsletter');
        Sfere::assertTracked(Event::VIEW_CART, fn (array $e) => $e['anonymousId'] === 'anon-1');
        Sfere::assertTracked(Event::ADD_TO_CART);
        $this->assertCount(1, $fake->tracked(Event::SIGN_UP));
    }

    public function test_flush_is_a_no_op(): void
    {
        Sfere::fake();
        Sfere::track('x', userId: '1');
        Sfere::flush();

        Sfere::assertSentCount(1);
    }

    public function test_the_fake_records_even_when_the_sdk_is_not_configured(): void
    {
        config(['sfere.host' => null]);
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(SfereClient::class);
        $this->assertFalse($this->app->make(Settings::class)->isOperational());

        Sfere::fake();
        Sfere::track(Event::PURCHASE, userId: '1');

        Sfere::assertTracked(Event::PURCHASE);
    }

    private function assertAssertionFails(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The assertion was expected to fail.');
    }
}
