<?php

namespace Sfere;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Sfere\Contracts\Dispatcher;
use Sfere\Exceptions\InvalidEventException;
use Sfere\Payload\EventBuilder;
use Sfere\Payload\EventFactory;
use Sfere\Payload\Scope;
use Sfere\Support\Resolvers;
use Sfere\Support\Settings;
use Throwable;

/**
 * The Sfere client. Bound as a singleton; `forUser()`, `anonymous()` and `withContext()` return
 * scoped copies and never change it.
 *
 * Every send method returns the event's messageId (also when the SDK is disabled or the event is
 * dropped), so it can be handed to a browser pixel as its event ID.
 */
class Sfere
{
    final public function __construct(
        protected readonly Settings $settings,
        protected readonly EventFactory $factory,
        protected readonly Dispatcher $dispatcher,
        protected readonly Resolvers $resolvers,
        protected readonly LoggerInterface $logger,
        protected readonly Scope $scope = new Scope,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     */
    public function track(
        string $event,
        array $properties = [],
        string|int|null $userId = null,
        ?string $anonymousId = null,
        ?DateTimeInterface $timestamp = null,
        array $context = [],
        ?string $messageId = null,
        ?array $integrations = null,
    ): string {
        return $this->sendEvent('track', ['event' => $event, 'properties' => $properties], $userId, $anonymousId, $timestamp, $context, $messageId, $integrations);
    }

    /**
     * @param  array<string, mixed>  $traits
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     */
    public function identify(
        string|int|null $userId = null,
        array $traits = [],
        ?string $anonymousId = null,
        ?DateTimeInterface $timestamp = null,
        array $context = [],
        ?string $messageId = null,
        ?array $integrations = null,
    ): string {
        return $this->sendEvent('identify', ['traits' => $traits], $userId, $anonymousId, $timestamp, $context, $messageId, $integrations);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     */
    public function page(
        ?string $name = null,
        array $properties = [],
        string|int|null $userId = null,
        ?string $anonymousId = null,
        ?DateTimeInterface $timestamp = null,
        array $context = [],
        ?string $messageId = null,
        ?array $integrations = null,
    ): string {
        return $this->sendEvent('page', ['name' => $name, 'properties' => $properties], $userId, $anonymousId, $timestamp, $context, $messageId, $integrations);
    }

    /**
     * @param  array<string, mixed>  $traits
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     */
    public function group(
        string|int $groupId,
        array $traits = [],
        string|int|null $userId = null,
        ?string $anonymousId = null,
        ?DateTimeInterface $timestamp = null,
        array $context = [],
        ?string $messageId = null,
        ?array $integrations = null,
    ): string {
        return $this->sendEvent('group', ['groupId' => (string) $groupId, 'traits' => $traits], $userId, $anonymousId, $timestamp, $context, $messageId, $integrations);
    }

    /**
     * Links a previous id (usually the anonymous id) to a user id.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     */
    public function alias(
        string|int $previousId,
        string|int|null $userId = null,
        ?DateTimeInterface $timestamp = null,
        array $context = [],
        ?string $messageId = null,
        ?array $integrations = null,
    ): string {
        return $this->sendEvent('alias', ['previousId' => (string) $previousId], $userId, null, $timestamp, $context, $messageId, $integrations);
    }

    /**
     * A fluent builder for any event type: `Sfere::event('track')->name('purchase')->...->send()`.
     */
    public function event(string $type): EventBuilder
    {
        return new EventBuilder($this, $type);
    }

    /**
     * A client whose events carry this user's id (and resolver traits as `context.traits`).
     * `null` clears the user: events fall back to the anonymous id.
     */
    public function forUser(?Authenticatable $user): static
    {
        $scope = $user === null
            ? $this->scope->withUser(null)
            : $this->scope->withUser($this->resolvers->userId($user), $this->resolvers->traits($user));

        return $this->withScope($scope);
    }

    /**
     * A client whose events carry this anonymous id.
     */
    public function anonymous(?string $anonymousId): static
    {
        return $this->withScope($this->scope->withAnonymousId($anonymousId));
    }

    /**
     * A client that merges this context into every event it sends.
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static
    {
        return $this->withScope($this->scope->withContext($context));
    }

    /**
     * How `forUser()` derives the user id. Default: `getAuthIdentifier()`, cast to string.
     *
     * @param  (Closure(Authenticatable): (string|int|null))|null  $resolver
     */
    public function resolveUserIdUsing(?Closure $resolver): void
    {
        $this->resolvers->resolveUserIdUsing($resolver);
    }

    /**
     * Traits `forUser()` adds as `context.traits` (e.g. email, phone). No default: sending PII is
     * the app's decision.
     *
     * @param  (Closure(Authenticatable): array<string, mixed>)|null  $resolver
     */
    public function resolveTraitsUsing(?Closure $resolver): void
    {
        $this->resolvers->resolveTraitsUsing($resolver);
    }

    /**
     * The end user's IP, user agent, locale, page, campaign and click IDs from a request (default:
     * the current one). Store it with an order to send later events (webhooks, cron) with it.
     *
     * @return array<string, mixed>
     */
    public function requestContext(?Request $request = null, ?string $pageUrl = null): array
    {
        return $this->factory->requestContext($request, $pageUrl);
    }

    /**
     * Sends (or enqueues) everything buffered now.
     */
    public function flush(): void
    {
        try {
            $this->dispatcher->flush();
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * Builds, validates and dispatches one event. Used by the public methods and EventBuilder.
     *
     * @internal
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $integrations
     */
    public function sendEvent(
        string $type,
        array $fields,
        string|int|null $userId = null,
        ?string $anonymousId = null,
        ?DateTimeInterface $timestamp = null,
        array $context = [],
        ?string $messageId = null,
        ?array $integrations = null,
    ): string {
        $messageId ??= (string) Str::uuid();

        try {
            $event = $this->factory->make(
                $type,
                $fields,
                $this->scope,
                $userId === null ? null : (string) $userId,
                $anonymousId,
                $timestamp,
                $context,
                $messageId,
                $integrations,
            );
            $this->dispatcher->dispatch($event);
        } catch (InvalidEventException $e) {
            if ($this->settings->throwExceptions) {
                throw $e;
            }
            $this->logger->warning($e->getMessage().' The event was dropped.', ['type' => $type, 'messageId' => $messageId]);
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }

        return $messageId;
    }

    /**
     * @internal
     */
    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * A copy of this client sending through another dispatcher (used by `Sfere::fake()`).
     *
     * @internal
     */
    public function withDispatcher(Dispatcher $dispatcher): static
    {
        return new static($this->settings, $this->factory, $dispatcher, $this->resolvers, $this->logger, $this->scope);
    }

    protected function withScope(Scope $scope): static
    {
        return new static($this->settings, $this->factory, $this->dispatcher, $this->resolvers, $this->logger, $scope);
    }

    /**
     * Analytics must never break the host app: log, and rethrow only when asked to.
     */
    private function handleFailure(Throwable $e): void
    {
        if ($this->settings->throwExceptions) {
            throw $e;
        }
        $this->logger->error('Sfere: '.$e->getMessage(), ['exception' => $e::class]);
    }
}
