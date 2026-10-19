<?php

namespace Sfere\Facades;

use Illuminate\Support\Facades\Facade;
use Sfere\Testing\SfereFake;

/**
 * @method static string track(string $event, array<string, mixed> $properties = [], string|int|null $userId = null, ?string $anonymousId = null, ?\DateTimeInterface $timestamp = null, array<string, mixed> $context = [], ?string $messageId = null, ?array<string, mixed> $integrations = null)
 * @method static string identify(string|int|null $userId = null, array<string, mixed> $traits = [], ?string $anonymousId = null, ?\DateTimeInterface $timestamp = null, array<string, mixed> $context = [], ?string $messageId = null, ?array<string, mixed> $integrations = null)
 * @method static string page(?string $name = null, array<string, mixed> $properties = [], string|int|null $userId = null, ?string $anonymousId = null, ?\DateTimeInterface $timestamp = null, array<string, mixed> $context = [], ?string $messageId = null, ?array<string, mixed> $integrations = null)
 * @method static string group(string|int $groupId, array<string, mixed> $traits = [], string|int|null $userId = null, ?string $anonymousId = null, ?\DateTimeInterface $timestamp = null, array<string, mixed> $context = [], ?string $messageId = null, ?array<string, mixed> $integrations = null)
 * @method static string alias(string|int $previousId, string|int|null $userId = null, ?\DateTimeInterface $timestamp = null, array<string, mixed> $context = [], ?string $messageId = null, ?array<string, mixed> $integrations = null)
 * @method static \Sfere\Payload\EventBuilder event(string $type)
 * @method static \Sfere\Sfere forUser(?\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Sfere\Sfere anonymous(?string $anonymousId)
 * @method static \Sfere\Sfere withContext(array<string, mixed> $context)
 * @method static void resolveUserIdUsing(?\Closure $resolver)
 * @method static void resolveTraitsUsing(?\Closure $resolver)
 * @method static array<string, mixed> requestContext(?\Illuminate\Http\Request $request = null, ?string $pageUrl = null)
 * @method static void flush()
 * @method static list<array<string, mixed>> recorded()
 * @method static list<array<string, mixed>> tracked(string $event)
 * @method static list<array<string, mixed>> identified(string $userId)
 * @method static list<array<string, mixed>> paged(?string $name = null)
 * @method static void assertTracked(string $event, ?callable $callback = null)
 * @method static void assertNotTracked(string $event, ?callable $callback = null)
 * @method static void assertIdentified(string $userId, ?callable $callback = null)
 * @method static void assertPaged(?string $name = null, ?callable $callback = null)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 *
 * The assert* and recorded/tracked/identified/paged methods exist after `Sfere::fake()`.
 *
 * @see \Sfere\Sfere
 */
class Sfere extends Facade
{
    /**
     * Record events in memory instead of sending them. Type-hinted `Sfere\Sfere` dependencies
     * resolved afterwards receive the fake too.
     */
    public static function fake(): SfereFake
    {
        $fake = SfereFake::from(app(\Sfere\Sfere::class));

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Sfere\Sfere::class;
    }
}
