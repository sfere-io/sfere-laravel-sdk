<?php

namespace Sfere\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Sfere\Context\RequestContext;
use Sfere\Sfere;
use Sfere\Support\Settings;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sends a server-side `page` event for successful GET requests that return HTML.
 *
 * Do not combine it with the browser SDK's page tracking on the same pages: each view would be
 * counted twice. Extend the class and override `$except` to change the excluded paths.
 */
class TrackPageViews
{
    /**
     * Paths (as matched by `Request::is()`) that are never tracked.
     *
     * @var list<string>
     */
    protected array $except = [
        'horizon*',
        'telescope*',
        'livewire*',
        '_debugbar*',
    ];

    public function __construct(protected Sfere $sfere, protected Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        try {
            if ($this->shouldTrack($request, $response)) {
                $user = $request->user();
                $user = $user instanceof Authenticatable ? $user : null;

                // A visitor with neither a login nor the browser SDK's anonymous id cannot be
                // attributed: skip instead of logging a dropped event on every view.
                if ($user === null && ! $this->hasAnonymousId($request)) {
                    return $response;
                }

                $query = $request->getQueryString();

                $this->sfere->forUser($user)->page(null, array_filter([
                    'url' => $request->fullUrl(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'search' => $query !== null ? '?'.$query : '',
                    'referrer' => $request->headers->get('referer'),
                ], fn (?string $value): bool => $value !== null));
            }
        } catch (Throwable) {
            // Analytics must never break the page.
        }

        return $response;
    }

    protected function hasAnonymousId(Request $request): bool
    {
        $cookie = $this->settings->anonymousIdCookie;

        return $cookie !== null && RequestContext::anonymousId($request, $cookie) !== null;
    }

    protected function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || ! $response->isSuccessful()) {
            return false;
        }
        if (! str_contains(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')) {
            return false;
        }

        return $this->except === [] || ! $request->is(...$this->except);
    }
}
