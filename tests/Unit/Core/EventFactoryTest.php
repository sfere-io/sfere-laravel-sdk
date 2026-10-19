<?php

namespace Sfere\Tests\Unit\Core;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Sfere\Exceptions\InvalidEventException;
use Sfere\Payload\EventFactory;
use Sfere\Payload\Scope;
use Sfere\Support\Library;
use Sfere\Tests\Support\CoreTestCase;

final class EventFactoryTest extends CoreTestCase
{
    private const LIBRARY = ['name' => '@fanfinity/sfere-laravel', 'version' => Library::VERSION, 'base' => 'laravel/framework@13.0.0'];

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     * @return array<string, mixed>
     */
    private function make(
        string $type,
        array $fields,
        ?string $userId = 'user_123',
        ?string $anonymousId = null,
        array $context = [],
        ?array $integrations = null,
        ?Scope $scope = null,
        ?EventFactory $factory = null,
    ): array {
        return ($factory ?? $this->factory())->make($type, $fields, $scope ?? new Scope, $userId, $anonymousId, null, $context, 'msg-1', $integrations);
    }

    public function test_track_wire_shape(): void
    {
        $event = $this->make('track', ['event' => 'purchase', 'properties' => ['value' => 10.0, 'currency' => 'SAR']], anonymousId: 'anon-1');

        $this->assertSame([
            'type' => 'track',
            'messageId' => 'msg-1',
            'userId' => 'user_123',
            'anonymousId' => 'anon-1',
            'timestamp' => '2026-09-29T10:00:00.123Z',
            'event' => 'purchase',
            'properties' => ['value' => 10.0, 'currency' => 'SAR', 'sfere_dedup_id' => 'msg-1'],
            'context' => ['library' => self::LIBRARY],
        ], $event);
    }

    public function test_track_always_carries_sfere_dedup_id_even_without_properties(): void
    {
        $event = $this->make('track', ['event' => 'login', 'properties' => []]);

        $this->assertSame(['sfere_dedup_id' => 'msg-1'], $event['properties']);
    }

    public function test_a_caller_sfere_dedup_id_is_kept_and_a_null_one_is_replaced(): void
    {
        $kept = $this->make('track', ['event' => 'purchase', 'properties' => ['sfere_dedup_id' => 'order_98765']]);
        $this->assertSame('order_98765', $kept['properties']['sfere_dedup_id']);

        $replaced = $this->make('track', ['event' => 'purchase', 'properties' => ['sfere_dedup_id' => null]]);
        $this->assertSame('msg-1', $replaced['properties']['sfere_dedup_id']);
    }

    public function test_identify_wire_shape(): void
    {
        $event = $this->make('identify', ['traits' => ['email' => 'fan@example.com']]);

        $this->assertSame([
            'type' => 'identify',
            'messageId' => 'msg-1',
            'userId' => 'user_123',
            'timestamp' => '2026-09-29T10:00:00.123Z',
            'traits' => ['email' => 'fan@example.com'],
            'context' => ['library' => self::LIBRARY],
        ], $event);
    }

    public function test_page_wire_shape_and_null_name_dropped(): void
    {
        $event = $this->make('page', ['name' => 'Pricing', 'properties' => ['url' => 'https://example.com/pricing']]);

        $this->assertSame(['type', 'messageId', 'userId', 'timestamp', 'name', 'properties', 'context'], array_keys($event));
        $this->assertSame('Pricing', $event['name']);

        $unnamed = $this->make('page', ['name' => null, 'properties' => []]);
        $this->assertSame(['type', 'messageId', 'userId', 'timestamp', 'context'], array_keys($unnamed));
    }

    public function test_group_and_alias_wire_shapes(): void
    {
        $group = $this->make('group', ['groupId' => 'company_42', 'traits' => ['name' => 'Acme']]);
        $this->assertSame(['type', 'messageId', 'userId', 'timestamp', 'traits', 'groupId', 'context'], array_keys($group));
        $this->assertSame('company_42', $group['groupId']);

        $alias = $this->make('alias', ['previousId' => 'anon-1']);
        $this->assertSame(['type', 'messageId', 'userId', 'timestamp', 'previousId', 'context'], array_keys($alias));
        $this->assertSame('anon-1', $alias['previousId']);
    }

    public function test_empty_traits_are_dropped_so_they_never_encode_as_a_json_list(): void
    {
        $event = $this->make('identify', ['traits' => []]);

        $this->assertArrayNotHasKey('traits', $event);
        $this->assertStringNotContainsString('[]', (string) json_encode($event));
    }

    public function test_fields_of_other_types_and_unknown_keys_never_reach_the_wire(): void
    {
        $event = $this->make('identify', [
            'traits' => ['a' => 1],
            'event' => 'nope',
            'properties' => ['x' => 1],
            '_metadata' => ['nodeVersion' => '1'],
            'sentAt' => '2026-09-29T10:00:00.000Z',
        ]);

        $this->assertSame(['type', 'messageId', 'userId', 'timestamp', 'traits', 'context'], array_keys($event));
    }

    public function test_integrations_only_when_set(): void
    {
        $this->assertArrayNotHasKey('integrations', $this->make('track', ['event' => 'e'], integrations: null));
        $this->assertArrayNotHasKey('integrations', $this->make('track', ['event' => 'e'], integrations: []));

        $event = $this->make('track', ['event' => 'e'], integrations: ['Meta' => false]);
        $this->assertSame(['Meta' => false], $event['integrations']);
        $this->assertSame('integrations', array_key_last($event));
    }

    public function test_timestamp_is_utc_iso8601_with_milliseconds(): void
    {
        $riyadh = new DateTimeImmutable('2026-01-02 03:04:05.678912', new DateTimeZone('Asia/Riyadh'));
        $event = $this->factory()->make('track', ['event' => 'e'], new Scope, 'u', null, $riyadh, [], 'm', null);

        $this->assertSame('2026-01-02T00:04:05.678Z', $event['timestamp']);

        $carbon = Carbon::parse('2026-01-02 03:04:05', 'Asia/Riyadh');
        $this->assertSame('2026-01-02T00:04:05.000Z', EventFactory::formatTimestamp($carbon));
        $this->assertSame('Asia/Riyadh', $carbon->getTimezone()->getName(), 'the caller\'s time is not mutated');
    }

    public function test_an_injected_clock_supplies_the_default_timestamp(): void
    {
        $factory = $this->factory(clock: fn () => new DateTimeImmutable('2025-05-05T05:05:05.555Z'));

        $this->assertSame('2025-05-05T05:05:05.555Z', $this->make('track', ['event' => 'e'], factory: $factory)['timestamp']);
    }

    public function test_context_merge_order_static_traits_scope_call_and_library_last(): void
    {
        $factory = $this->factory([
            'context.static' => ['app' => ['name' => 'shop'], 'locale' => 'static', 'traits' => ['plan' => 'static', 'tier' => 'gold'], 'tags' => ['a', 'b']],
        ]);
        $scope = (new Scope)
            ->withUser('u-scope', ['plan' => 'resolver', 'email' => 'fan@example.com'])
            ->withContext(['locale' => 'scope', 'campaign' => ['source' => 'scope', 'medium' => 'email']]);

        $event = $this->make('track', ['event' => 'e'], userId: null, scope: $scope, factory: $factory, context: [
            'campaign' => ['source' => 'call'],
            'tags' => ['z'],
            'library' => ['name' => 'spoofed'],
        ]);

        $this->assertSame('u-scope', $event['userId']);
        $this->assertSame([
            'app' => ['name' => 'shop'],
            'locale' => 'scope',
            'traits' => ['plan' => 'resolver', 'tier' => 'gold', 'email' => 'fan@example.com'],
            'tags' => ['z'],
            'campaign' => ['source' => 'call', 'medium' => 'email'],
            'library' => self::LIBRARY,
        ], $event['context']);
    }

    public function test_explicit_ids_win_over_the_scope(): void
    {
        $scope = (new Scope)->withUser('scope-user')->withAnonymousId('scope-anon');

        $event = $this->make('track', ['event' => 'e'], userId: 'explicit', anonymousId: 'explicit-anon', scope: $scope);
        $this->assertSame('explicit', $event['userId']);
        $this->assertSame('explicit-anon', $event['anonymousId']);

        $fromScope = $this->make('track', ['event' => 'e'], userId: null, scope: $scope);
        $this->assertSame('scope-user', $fromScope['userId']);
        $this->assertSame('scope-anon', $fromScope['anonymousId']);
    }

    public function test_identify_merges_resolver_traits_under_explicit_traits(): void
    {
        $scope = (new Scope)->withUser('u', ['email' => 'resolver@example.com', 'phone' => '+966500000000']);

        $event = $this->make('identify', ['traits' => ['email' => 'explicit@example.com']], userId: null, scope: $scope);

        $this->assertSame(['email' => 'explicit@example.com', 'phone' => '+966500000000'], $event['traits']);
        $this->assertSame(['email' => 'resolver@example.com', 'phone' => '+966500000000'], $event['context']['traits']);
    }

    public function test_unsupported_types_are_invalid(): void
    {
        $this->expectException(InvalidEventException::class);
        $this->expectExceptionMessage('unsupported type');

        $this->make('screen', ['name' => 'Home']);
    }

    public function test_the_factory_validates_the_event(): void
    {
        $this->expectException(InvalidEventException::class);
        $this->expectExceptionMessage('userId or an anonymousId');

        $this->make('track', ['event' => 'e'], userId: null);
    }

    public function test_request_context_is_empty_outside_a_request(): void
    {
        $this->assertSame([], $this->factory()->requestContext());
    }

    public function test_the_wire_event_encodes_as_objects(): void
    {
        $json = (string) json_encode($this->make('identify', ['traits' => ['email' => 'a@b.test']]));

        $decoded = json_decode($json);
        $this->assertIsObject($decoded);
        $this->assertIsObject($decoded->traits);
        $this->assertIsObject($decoded->context);
        $this->assertIsObject($decoded->context->library);
    }
}
