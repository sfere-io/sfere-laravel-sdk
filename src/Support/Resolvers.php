<?php

namespace Sfere\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * App-wide callbacks set once (usually in a service provider's boot method):
 * `Sfere::resolveUserIdUsing(...)` and `Sfere::resolveTraitsUsing(...)`.
 */
final class Resolvers
{
    /** @var (Closure(Authenticatable): (string|int|null))|null */
    private ?Closure $userId = null;

    /** @var (Closure(Authenticatable): array<string, mixed>)|null */
    private ?Closure $traits = null;

    /**
     * @param  (Closure(Authenticatable): (string|int|null))|null  $resolver
     */
    public function resolveUserIdUsing(?Closure $resolver): void
    {
        $this->userId = $resolver;
    }

    /**
     * @param  (Closure(Authenticatable): array<string, mixed>)|null  $resolver
     */
    public function resolveTraitsUsing(?Closure $resolver): void
    {
        $this->traits = $resolver;
    }

    public function userId(Authenticatable $user): ?string
    {
        $id = $this->userId !== null ? ($this->userId)($user) : $user->getAuthIdentifier();

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function traits(Authenticatable $user): array
    {
        return $this->traits !== null ? ($this->traits)($user) : [];
    }
}
