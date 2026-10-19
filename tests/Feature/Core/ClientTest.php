<?php

namespace Sfere\Tests\Feature\Core;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use RuntimeException;
use Sfere\Contracts\Dispatcher;
use Sfere\Ecommerce\Event;
use Sfere\Exceptions\InvalidEventException;
use Sfere\Tests\Support\CoreTestCase;

final class ClientTest extends CoreTestCase
{
    public function test_track_dispatches_the_event_and_returns_its_message_id(): void
    {
        $id = $this->client()->track(Event::PURCHASE, ['value' => 256.96, 'currency' => 'SAR'], userId: '10293');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $event = $this->dispatcher->last();
        $this->assertSame($id, $event['messageId']);
        $this->assertSame('purchase', $event['event']);
        $this->assertSame('10293', $event['userId']);
        $this->assertSame($id, $event['properties']['sfere_dedup_id']);
    }

    public function test_message_ids_are_unique_and_a_caller_message_id_is_used(): void
    {
        $client = $this->client();

        $this->assertNotSame($client->track('e', userId: 'u'), $client->track('e', userId: 'u'));
        $this->assertSame('purchase-order_98765', $client->track('purchase', userId: 'u', messageId: 'purchase-order_98765'));
        $this->assertSame('purchase-order_98765', $this->dispatcher->last()['messageId']);
    }

    public function test_integer_user_ids_are_cast_to_strings(): void
    {
        $this->client()->identify(10293, ['email' => 'fan@example.com']);

        $this->assertSame('10293', $this->dispatcher->last()['userId']);
    }

    public function test_invalid_events_are_logged_and_dropped_and_still_return_a_message_id(): void
    {
        $client = $this->client();

        $this->assertSame('given-id', $client->track('', userId: 'u', messageId: 'given-id'));
        $this->assertNotSame('', $client->track('e'));                     // no user or anonymous id
        $this->assertNotSame('', $client->track('e', userId: 0));          // placeholder id
        $client->track('e', userId: 'u', messageId: 'not valid!');

        $this->assertSame([], $this->dispatcher->events);
        $warnings = $this->logger->messages('warning');
        $this->assertCount(4, $warnings);
        $this->assertStringContainsString('event name is required', $warnings[0]);
        $this->assertStringContainsString('The event was dropped.', $warnings[0]);
        $this->assertSame('given-id', $this->logger->records[0]['context']['messageId']);
    }

    public function test_invalid_events_throw_when_throw_exceptions_is_on(): void
    {
        $this->expectException(InvalidEventException::class);

        $this->client(['throw_exceptions' => true])->track('e');
    }

    public function test_dispatcher_failures_never_reach_the_caller_by_default(): void
    {
        $client = $this->client()->withDispatcher($this->failingDispatcher());

        $id = $client->track('e', userId: 'u');
        $client->flush();

        $this->assertNotSame('', $id);
        $errors = $this->logger->messages('error');
        $this->assertCount(2, $errors);
        $this->assertSame('Sfere: boom', $errors[0]);
    }

    public function test_dispatcher_failures_are_rethrown_when_throw_exceptions_is_on(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->client(['throw_exceptions' => true])->withDispatcher($this->failingDispatcher())->flush();
    }

    public function test_flush_is_forwarded_to_the_dispatcher(): void
    {
        $this->client()->flush();

        $this->assertSame(1, $this->dispatcher->flushes);
    }

    public function test_for_user_uses_the_auth_identifier(): void
    {
        $this->client()->forUser(new GenericUser(['id' => 42, 'email' => 'fan@example.com']))->track(Event::SIGN_UP, ['method' => 'email']);

        $event = $this->dispatcher->last();
        $this->assertSame('42', $event['userId']);
        $this->assertArrayNotHasKey('traits', $event['context'], 'no traits without a trait resolver');
    }

    public function test_custom_user_id_and_trait_resolvers(): void
    {
        $client = $this->client();
        $client->resolveUserIdUsing(fn ($user) => $user->uuid);
        $client->resolveTraitsUsing(fn ($user) => ['email' => $user->email, 'phone' => $user->phone]);

        $user = new GenericUser(['id' => 1, 'uuid' => 'c0ffee-42', 'email' => 'fan@example.com', 'phone' => '+966500000000']);
        $scoped = $client->forUser($user);

        $scoped->track('e');
        $track = $this->dispatcher->last();
        $this->assertSame('c0ffee-42', $track['userId']);
        $this->assertSame(['email' => 'fan@example.com', 'phone' => '+966500000000'], $track['context']['traits']);

        $scoped->identify(traits: ['first_name' => 'Sara', 'email' => 'other@example.com']);
        $identify = $this->dispatcher->last();
        $this->assertSame('c0ffee-42', $identify['userId']);
        $this->assertSame(['email' => 'other@example.com', 'phone' => '+966500000000', 'first_name' => 'Sara'], $identify['traits']);
    }

    public function test_a_resolver_returning_no_id_leaves_the_event_anonymous(): void
    {
        $client = $this->client();
        $client->resolveUserIdUsing(fn ($user) => null);

        $client->forUser(new GenericUser(['id' => 1]))->anonymous('anon-1')->track('e');

        $event = $this->dispatcher->last();
        $this->assertArrayNotHasKey('userId', $event);
        $this->assertSame('anon-1', $event['anonymousId']);
    }

    public function test_for_user_null_clears_the_user(): void
    {
        $client = $this->client()->forUser(new GenericUser(['id' => 7]));

        $client->forUser(null)->track('e', anonymousId: 'anon-1');

        $this->assertArrayNotHasKey('userId', $this->dispatcher->last());
    }

    public function test_anonymous_sets_the_anonymous_id(): void
    {
        $this->client()->anonymous('a1b2c3')->track(Event::BEGIN_CHECKOUT, ['value' => 1]);

        $event = $this->dispatcher->last();
        $this->assertSame('a1b2c3', $event['anonymousId']);
        $this->assertArrayNotHasKey('userId', $event);
    }

    public function test_scoped_clients_are_immutable_copies(): void
    {
        $client = $this->client();
        $scoped = $client->withContext(['campaign' => ['source' => 'newsletter']]);
        $user = $scoped->forUser(new GenericUser(['id' => 9]));

        $this->assertNotSame($client, $scoped);
        $this->assertNotSame($scoped, $user);

        $client->track('plain', anonymousId: 'x');
        $this->assertArrayNotHasKey('campaign', $this->dispatcher->last()['context']);

        $scoped->track('scoped', anonymousId: 'x');
        $this->assertArrayNotHasKey('userId', $this->dispatcher->last());
        $this->assertSame(['source' => 'newsletter'], $this->dispatcher->last()['context']['campaign']);

        $user->withContext(['campaign' => ['medium' => 'email']])->track('both');
        $this->assertSame('9', $this->dispatcher->last()['userId']);
        $this->assertSame(['source' => 'newsletter', 'medium' => 'email'], $this->dispatcher->last()['context']['campaign']);
    }

    public function test_page_group_and_alias(): void
    {
        $client = $this->client();

        $client->page('Pricing', ['url' => 'https://example.com/pricing'], userId: '10293');
        $this->assertSame(['type' => 'page', 'name' => 'Pricing'], array_intersect_key($this->dispatcher->last(), ['type' => 1, 'name' => 1]));

        $client->group(42, ['name' => 'Acme'], userId: '10293');
        $this->assertSame('42', $this->dispatcher->last()['groupId']);

        $client->alias(previousId: 'anon-1', userId: 10293);
        $alias = $this->dispatcher->last();
        $this->assertSame(['alias', 'anon-1', '10293'], [$alias['type'], $alias['previousId'], $alias['userId']]);
    }

    public function test_timestamp_context_and_integrations_overrides(): void
    {
        $this->client()->track('import_completed', [], userId: 'u1', timestamp: Carbon::parse('2026-09-28T10:00:00Z'), context: ['source' => 'csv'], integrations: ['Meta' => false]);

        $event = $this->dispatcher->last();
        $this->assertSame('2026-09-28T10:00:00.000Z', $event['timestamp']);
        $this->assertSame('csv', $event['context']['source']);
        $this->assertSame(['Meta' => false], $event['integrations']);
    }

    public function test_fluent_builder_for_every_type(): void
    {
        $client = $this->client();

        $id = $client->event('track')
            ->name(Event::ADD_TO_CART)
            ->userId(10293)
            ->messageId('cart-abc-1')
            ->properties(['currency' => 'SAR'])
            ->properties(['value' => 89.99])
            ->context(['campaign' => ['source' => 'newsletter', 'tags' => ['a', 'b']]])
            ->context(['campaign' => ['tags' => ['c']]])
            ->timestamp(Carbon::parse('2026-01-01T00:00:00Z'))
            ->integrations(['TikTok' => false])
            ->send();

        $this->assertSame('cart-abc-1', $id);
        $track = $this->dispatcher->last();
        $this->assertSame('add_to_cart', $track['event']);
        $this->assertSame('10293', $track['userId']);
        $this->assertSame(['currency' => 'SAR', 'value' => 89.99, 'sfere_dedup_id' => 'cart-abc-1'], $track['properties']);
        $this->assertSame(['source' => 'newsletter', 'tags' => ['c']], $track['context']['campaign'], 'lists are replaced, as in every context merge');
        $this->assertSame('2026-01-01T00:00:00.000Z', $track['timestamp']);
        $this->assertSame(['TikTok' => false], $track['integrations']);

        $client->event('page')->name('Home')->anonymousId('anon')->properties(['path' => '/'])->send();
        $this->assertSame(['page', 'Home', 'anon'], [$this->dispatcher->last()['type'], $this->dispatcher->last()['name'], $this->dispatcher->last()['anonymousId']]);

        $client->event('identify')->userId('u')->traits(['email' => 'a@b.test'])->traits(['phone' => '1'])->send();
        $this->assertSame(['email' => 'a@b.test', 'phone' => '1'], $this->dispatcher->last()['traits']);

        $client->event('group')->userId('u')->groupId(5)->traits(['name' => 'Acme'])->send();
        $this->assertSame('5', $this->dispatcher->last()['groupId']);

        $client->event('alias')->userId('u')->previousId('anon')->send();
        $this->assertSame('anon', $this->dispatcher->last()['previousId']);

        $this->assertCount(5, $this->dispatcher->events);
    }

    public function test_builder_with_an_unknown_type_is_dropped(): void
    {
        $client = $this->client();

        $client->event('screen')->name('Home')->userId('u')->send();

        $this->assertSame([], $this->dispatcher->events);
        $this->assertStringContainsString('unsupported type', $this->logger->messages('warning')[0]);
    }

    public function test_request_context_is_empty_outside_a_request(): void
    {
        $this->assertSame([], $this->client()->requestContext());
    }

    private function failingDispatcher(): Dispatcher
    {
        return new class implements Dispatcher
        {
            public function dispatch(array $event): void
            {
                throw new RuntimeException('boom');
            }

            public function flush(): void
            {
                throw new RuntimeException('boom');
            }
        };
    }
}
