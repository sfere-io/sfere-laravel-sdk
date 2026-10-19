<?php

namespace Sfere\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use Sfere\Sfere;

/**
 * A Sfere client that records the fully built wire events instead of sending them:
 *
 * ```php
 * Sfere::fake();
 * // ... run app code ...
 * Sfere::assertTracked('purchase', fn (array $event) => $event['properties']['value'] === 256.96);
 * ```
 *
 * Scoped copies (`forUser()`, `anonymous()`, `withContext()`) stay fakes and record into the same
 * list. Send methods still return the messageId; nothing goes over HTTP.
 */
class SfereFake extends Sfere
{
    public static function from(Sfere $client): static
    {
        return new static(
            $client->settings,
            $client->factory,
            new RecordingDispatcher,
            $client->resolvers,
            $client->logger,
        );
    }

    /**
     * Every recorded event, in call order.
     *
     * @return list<array<string, mixed>>
     */
    public function recorded(): array
    {
        $dispatcher = $this->getDispatcher();

        return $dispatcher instanceof RecordingDispatcher ? $dispatcher->events() : [];
    }

    /**
     * Recorded `track` events with this name.
     *
     * @return list<array<string, mixed>>
     */
    public function tracked(string $event): array
    {
        return $this->ofType('track', fn (array $e): bool => ($e['event'] ?? null) === $event);
    }

    /**
     * Recorded `identify` events for this user id.
     *
     * @return list<array<string, mixed>>
     */
    public function identified(string $userId): array
    {
        return $this->ofType('identify', fn (array $e): bool => ($e['userId'] ?? null) === $userId);
    }

    /**
     * Recorded `page` events (all of them, or those with this name).
     *
     * @return list<array<string, mixed>>
     */
    public function paged(?string $name = null): array
    {
        return $this->ofType('page', fn (array $e): bool => $name === null || ($e['name'] ?? null) === $name);
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     */
    public function assertTracked(string $event, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->filter($this->tracked($event), $callback),
            "The expected [{$event}] event was not tracked".($callback !== null ? ' with matching data.' : '.'),
        );
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     */
    public function assertNotTracked(string $event, ?callable $callback = null): void
    {
        PHPUnit::assertEmpty(
            $this->filter($this->tracked($event), $callback),
            "The unexpected [{$event}] event was tracked.",
        );
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     */
    public function assertIdentified(string $userId, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->filter($this->identified($userId), $callback),
            "User [{$userId}] was not identified".($callback !== null ? ' with matching data.' : '.'),
        );
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     */
    public function assertPaged(?string $name = null, ?callable $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->filter($this->paged($name), $callback),
            'The expected page event'.($name !== null ? " [{$name}]" : '').' was not sent.',
        );
    }

    public function assertNothingSent(): void
    {
        $count = count($this->recorded());

        PHPUnit::assertSame(0, $count, "{$count} unexpected Sfere event(s) were sent.");
    }

    public function assertSentCount(int $count): void
    {
        $actual = count($this->recorded());

        PHPUnit::assertSame($count, $actual, "Expected {$count} Sfere event(s), {$actual} were sent.");
    }

    /**
     * Nothing is buffered: events are recorded as they are sent.
     */
    public function flush(): void {}

    /**
     * @param  callable(array<string, mixed>): bool  $match
     * @return list<array<string, mixed>>
     */
    private function ofType(string $type, callable $match): array
    {
        return array_values(array_filter(
            $this->recorded(),
            fn (array $e): bool => ($e['type'] ?? null) === $type && $match($e),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  (callable(array<string, mixed>): bool)|null  $callback
     * @return list<array<string, mixed>>
     */
    private function filter(array $events, ?callable $callback): array
    {
        return $callback === null ? $events : array_values(array_filter($events, fn (array $e): bool => (bool) $callback($e)));
    }
}
