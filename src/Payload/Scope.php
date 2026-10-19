<?php

namespace Sfere\Payload;

use Sfere\Context\ContextMerger;

/**
 * Who and what a scoped client (`forUser()`, `anonymous()`, `withContext()`) adds to its events.
 * Immutable: every `with*` returns a copy, so a scope never leaks between Octane requests.
 */
final readonly class Scope
{
    /**
     * @param  array<string, mixed>  $traits  from the trait resolver, sent as `context.traits`
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public ?string $userId = null,
        public ?string $anonymousId = null,
        public array $traits = [],
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $traits
     */
    public function withUser(?string $userId, array $traits = []): self
    {
        return new self($userId, $this->anonymousId, $traits, $this->context);
    }

    public function withAnonymousId(?string $anonymousId): self
    {
        return new self($this->userId, $anonymousId, $this->traits, $this->context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): self
    {
        /** @var array<string, mixed> $merged */
        $merged = ContextMerger::merge($this->context, $context);

        return new self($this->userId, $this->anonymousId, $this->traits, $merged);
    }
}
