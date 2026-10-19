<?php

namespace Sfere\Payload;

use DateTimeInterface;
use Sfere\Sfere;

/**
 * Fluent builder for any event type:
 *
 * ```php
 * Sfere::event('track')->name('add_to_cart')->userId('10293')->properties([...])->send();
 * ```
 *
 * `name()` is the event name on `track` and the page name on `page`.
 */
final class EventBuilder
{
    private ?string $name = null;

    private ?string $userId = null;

    private ?string $anonymousId = null;

    private ?string $messageId = null;

    private ?DateTimeInterface $timestamp = null;

    /** @var array<string, mixed> */
    private array $properties = [];

    /** @var array<string, mixed> */
    private array $traits = [];

    private ?string $groupId = null;

    private ?string $previousId = null;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, mixed>|null */
    private ?array $integrations = null;

    public function __construct(private readonly Sfere $client, private readonly string $type) {}

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function userId(string|int|null $userId): self
    {
        $this->userId = $userId === null ? null : (string) $userId;

        return $this;
    }

    public function anonymousId(?string $anonymousId): self
    {
        $this->anonymousId = $anonymousId;

        return $this;
    }

    public function messageId(string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function timestamp(DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function properties(array $properties): self
    {
        $this->properties = array_merge($this->properties, $properties);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $traits
     */
    public function traits(array $traits): self
    {
        $this->traits = array_merge($this->traits, $traits);

        return $this;
    }

    public function groupId(string|int $groupId): self
    {
        $this->groupId = (string) $groupId;

        return $this;
    }

    public function previousId(string|int $previousId): self
    {
        $this->previousId = (string) $previousId;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function context(array $context): self
    {
        $this->context = array_replace_recursive($this->context, $context);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $integrations
     */
    public function integrations(array $integrations): self
    {
        $this->integrations = $integrations;

        return $this;
    }

    /**
     * Builds and dispatches the event; returns its messageId.
     */
    public function send(): string
    {
        $fields = match ($this->type) {
            'track' => ['event' => $this->name, 'properties' => $this->properties],
            'page' => ['name' => $this->name, 'properties' => $this->properties],
            'identify' => ['traits' => $this->traits],
            'group' => ['groupId' => $this->groupId, 'traits' => $this->traits],
            'alias' => ['previousId' => $this->previousId],
            default => [],
        };

        return $this->client->sendEvent(
            $this->type,
            $fields,
            $this->userId,
            $this->anonymousId,
            $this->timestamp,
            $this->context,
            $this->messageId,
            $this->integrations,
        );
    }
}
