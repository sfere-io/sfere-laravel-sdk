<?php

namespace Sfere\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sfere\Exceptions\InvalidEventException;
use Sfere\Payload\Validator;

final class ValidatorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function event(array $overrides = [], array $unset = []): array
    {
        $event = array_merge([
            'type' => 'track',
            'messageId' => 'order-456-purchase',
            'userId' => 'user_123',
            'timestamp' => '2026-09-29T10:00:00.000Z',
            'event' => 'purchase',
            'properties' => ['value' => 1.5],
            'context' => [],
        ], $overrides);

        foreach ($unset as $key) {
            unset($event[$key]);
        }

        return $event;
    }

    public function test_a_valid_event_passes(): void
    {
        Validator::validate(self::event());
        Validator::validate(self::event(['userId' => null, 'anonymousId' => 'anon-1'], ['userId']));
        Validator::validate(self::event(['messageId' => str_repeat('a', 64)]));
        Validator::validate(self::event(['messageId' => 'A-z_0.9']));
        Validator::validate(self::event(['event' => str_repeat('é', 128)]));
        Validator::validate(self::event(['userId' => str_repeat('u', 512)]));
        Validator::validate(['type' => 'group', 'messageId' => 'g', 'userId' => 'u', 'groupId' => 'company_42']);
        Validator::validate(['type' => 'alias', 'messageId' => 'a', 'userId' => 'u', 'previousId' => 'anon']);
        Validator::validate(['type' => 'identify', 'messageId' => 'i', 'userId' => 'u']);
        Validator::validate(['type' => 'page', 'messageId' => 'p', 'anonymousId' => 'anon']);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidEvents(): array
    {
        return [
            'messageId with a space' => [self::event(['messageId' => 'order 456']), 'messageId'],
            'messageId with a colon' => [self::event(['messageId' => 'zid:1']), 'messageId'],
            'messageId over 64' => [self::event(['messageId' => str_repeat('a', 65)]), 'messageId'],
            'empty messageId' => [self::event(['messageId' => '']), 'messageId'],
            'missing messageId' => [self::event([], ['messageId']), 'messageId'],
            'no user or anonymous id' => [self::event([], ['userId']), 'userId or an anonymousId'],
            'placeholder 0' => [self::event(['userId' => '0']), 'placeholder'],
            'placeholder -1' => [self::event(['userId' => '-1']), 'placeholder'],
            'placeholder NULL' => [self::event(['userId' => 'NULL']), 'placeholder'],
            'placeholder Undefined' => [self::event(['userId' => 'Undefined']), 'placeholder'],
            'placeholder Test' => [self::event(['userId' => 'Test']), 'placeholder'],
            'placeholder NA' => [self::event(['userId' => 'NA']), 'placeholder'],
            'placeholder n/a padded' => [self::event(['userId' => ' n/a ']), 'placeholder'],
            'empty userId' => [self::event(['userId' => '']), 'userId must be a non-empty string'],
            'int userId' => [self::event(['userId' => 123]), 'userId must be a non-empty string'],
            'userId over 512' => [self::event(['userId' => str_repeat('u', 513)]), 'longer than 512'],
            'empty anonymousId' => [self::event(['anonymousId' => '']), 'anonymousId must be a non-empty string'],
            'track without event' => [self::event([], ['event']), 'event name is required'],
            'track with blank event' => [self::event(['event' => '  ']), 'event name is required'],
            'event name over 128' => [self::event(['event' => str_repeat('e', 129)]), 'longer than 128'],
            'group without groupId' => [['type' => 'group', 'messageId' => 'g', 'userId' => 'u'], 'groupId'],
            'group with empty groupId' => [['type' => 'group', 'messageId' => 'g', 'userId' => 'u', 'groupId' => ''], 'groupId'],
            'alias without previousId' => [['type' => 'alias', 'messageId' => 'a', 'userId' => 'u'], 'previousId'],
            'alias without userId' => [['type' => 'alias', 'messageId' => 'a', 'anonymousId' => 'x', 'previousId' => 'p'], 'userId is required'],
            'over 900 KB' => [self::event(['properties' => ['blob' => str_repeat('x', 900_001)]]), '900 KB'],
            'not encodable' => [self::event(['properties' => ['value' => NAN]]), 'JSON'],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    #[DataProvider('invalidEvents')]
    public function test_invalid_events_are_rejected(array $event, string $reason): void
    {
        $this->expectException(InvalidEventException::class);
        $this->expectExceptionMessage($reason);

        Validator::validate($event);
    }

    public function test_messages_never_include_the_offending_values(): void
    {
        try {
            Validator::validate(self::event(['userId' => 'TEST']));
            $this->fail('Expected an exception.');
        } catch (InvalidEventException $e) {
            $this->assertStringStartsWith('Sfere: invalid track event:', $e->getMessage());
            $this->assertStringNotContainsString('TEST', $e->getMessage());
        }
    }
}
