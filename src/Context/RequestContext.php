<?php

namespace Sfere\Context;

use Closure;
use Illuminate\Http\Request;

/**
 * Port of `requestContext()` from @fanfinity/sfere-node (packages/node/src/context.ts).
 *
 * STUB: signatures are final; the implementation is filled in by the context module.
 */
final class RequestContext
{
    /**
     * The context a server event needs to match on ad platforms, from the customer's request.
     *
     * @param  (Closure(): int)|null  $now  clock in epoch milliseconds (tests pass a fixed one)
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request, bool $trustProxy = true, ?string $pageUrl = null, ?Closure $now = null): array
    {
        return [];
    }

    /**
     * The browser SDK's anonymous id from the raw Cookie header, unwrapped from JSON quotes.
     */
    public static function anonymousId(Request $request, string $cookieName): ?string
    {
        return null;
    }
}
