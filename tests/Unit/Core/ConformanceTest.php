<?php

namespace Sfere\Tests\Unit\Core;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Sfere\Payload\Scope;
use Sfere\Tests\Support\CoreTestCase;

/**
 * Golden wire payloads (tests/Fixtures) built from sfere-sdk docs/HTTP-API.md: the SDK must send
 * exactly these envelopes, in this key order. `context.library.version` and `.base` are ignored.
 */
final class ConformanceTest extends CoreTestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string|null, 3: string|null, 4: array<string, mixed>, 5: string}>
     */
    public static function cases(): array
    {
        return [
            'track' => ['track', [
                'event' => 'purchase',
                'properties' => [
                    'transaction_id' => 'order_456',
                    'currency' => 'SAR',
                    'value' => 179.98,
                    'items' => [['item_id' => 'sku_123', 'item_name' => 'Jersey', 'price' => 89.99, 'quantity' => 2]],
                    'sfere_dedup_id' => 'order-456-purchase',
                ],
            ], 'user_123', null, [
                'ip' => '203.0.113.7',
                'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
                'traits' => ['email' => 'fan@example.com', 'phone' => '+966500000000'],
                'clientIds' => ['fbp' => 'fb.1.1727500000000.123', 'gclid' => 'CjwKCAj'],
                'page' => ['url' => 'https://shop.example.com/checkout/thanks'],
            ], 'order-456-purchase'],
            'identify' => ['identify', [
                'traits' => ['email' => 'fan@example.com', 'first_name' => 'Sara'],
            ], 'user_123', null, ['ip' => '203.0.113.7', 'userAgent' => 'Mozilla/5.0'], 'm-1'],
            'page' => ['page', [
                'name' => 'Pricing',
                'properties' => ['url' => 'https://shop.example.com/pricing', 'path' => '/pricing', 'title' => 'Pricing'],
            ], null, 'a1b2c3d4-anon', [
                'ip' => '203.0.113.7',
                'userAgent' => 'Mozilla/5.0',
                'page' => ['url' => 'https://shop.example.com/pricing', 'path' => '/pricing', 'search' => '', 'referrer' => 'https://www.google.com/'],
            ], 'page-pricing-1'],
            'group' => ['group', [
                'groupId' => 'company_42',
                'traits' => ['name' => 'Acme', 'plan' => 'pro'],
            ], 'user_123', null, [], 'group-company_42'],
            'alias' => ['alias', ['previousId' => 'a1b2c3d4-anon'], 'user_123', null, [], 'alias-user_123'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $context
     */
    #[DataProvider('cases')]
    public function test_wire_payload_matches_the_golden_fixture(string $type, array $fields, ?string $userId, ?string $anonymousId, array $context, string $messageId): void
    {
        $event = $this->factory()->make(
            $type,
            $fields,
            new Scope,
            $userId,
            $anonymousId,
            new DateTimeImmutable('2026-09-29T10:00:00.000Z'),
            $context,
            $messageId,
        );

        // Compare what goes on the wire, after a JSON round trip.
        $wire = json_decode((string) json_encode($event, JSON_PRESERVE_ZERO_FRACTION), true);
        $this->assertIsArray($wire);
        $this->assertSame('@fanfinity/sfere-laravel', $wire['context']['library']['name']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $wire['context']['library']['version']);
        $this->assertStringStartsWith('laravel/framework@', $wire['context']['library']['base']);
        $wire['context']['library']['version'] = '*';
        $wire['context']['library']['base'] = '*';

        $fixture = json_decode((string) file_get_contents(__DIR__."/../../Fixtures/{$type}.json"), true);

        $this->assertSame($fixture, $wire);
    }
}
