<?php

namespace Sfere\Context;

use Closure;
use Illuminate\Http\Request;

/**
 * Builds the event `context` a server-side event needs to match on ad platforms, from the end
 * user's request: their IP and user agent (ingestion never fills these in for server events,
 * WIRE-CONTRACT §4), language, page, UTM / Google Ads campaign parameters, and the click IDs in
 * their first-party cookies and landing URL.
 *
 * A port of `requestContext()` in @fanfinity/sfere-node (packages/node/src/context.ts).
 */
final class RequestContext
{
    private const UTM = [
        'utm_source' => 'source',
        'utm_medium' => 'medium',
        'utm_campaign' => 'name',
        'utm_term' => 'term',
        'utm_content' => 'content',
    ];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * The context of a Laravel request.
     *
     * Cookies are read from the raw `Cookie` header, never from `$request->cookie()`: Laravel's
     * EncryptCookies middleware turns every cookie it did not encrypt (`_fbp`, `_gcl_aw`, …) into
     * null in the cookie bag.
     *
     * @param  bool  $trustProxy  true: the left-most `X-Forwarded-For`, then `X-Real-IP`, then
     *                            `REMOTE_ADDR`, and the page origin from `X-Forwarded-Proto` /
     *                            `X-Forwarded-Host`. false: `$request->ip()` (the app's own
     *                            TrustProxies config) and the `Host` header.
     * @param  string|null  $pageUrl  the page the visitor is on, when the request is an API call from it
     * @param  (Closure(): int)|null  $now  clock in epoch milliseconds (tests pass a fixed one)
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request, bool $trustProxy = true, ?string $pageUrl = null, ?Closure $now = null): array
    {
        $remoteAddr = $request->server->get('REMOTE_ADDR');

        return self::build(
            headers: $request->headers->all(),
            url: $request->getRequestUri(),
            clientAddress: $trustProxy ? (is_string($remoteAddr) ? $remoteAddr : null) : $request->ip(),
            trustProxy: $trustProxy,
            pageUrl: $pageUrl,
            now: $now,
            secure: $request->isSecure(),
            fallbackCookies: self::cookieBag($request),
        );
    }

    /**
     * The browser SDK's anonymous id from the raw Cookie header, unwrapped from JSON quotes
     * (`"…"` or `%22…%22`). Null when absent, empty, or not usable as an id.
     */
    public static function anonymousId(Request $request, string $cookieName): ?string
    {
        $cookies = self::cookies($request->headers->all(), self::cookieBag($request));
        $value = trim($cookies[$cookieName] ?? '');

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = trim(substr($value, 1, -1));
        }

        if ($value === '' || mb_strlen($value) > 512 || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return $value;
    }

    /**
     * The framework-independent core: everything `fromRequest()` does, from plain inputs.
     *
     * @param  array<string, string|list<string|null>|null>  $headers  any header-name case
     * @param  string|null  $url  the request URL: absolute, or a path + query resolved against the Host
     * @param  string|null  $clientAddress  the IP used when no trusted forwarding header has one
     * @param  (Closure(): int)|null  $now  clock in epoch milliseconds
     * @param  bool  $secure  whether the request reached the app over https (when no X-Forwarded-Proto applies)
     * @param  array<string, string>  $fallbackCookies  used only when there is no Cookie header
     * @return array<string, mixed>
     */
    public static function build(
        array $headers,
        ?string $url,
        ?string $clientAddress = null,
        bool $trustProxy = true,
        ?string $pageUrl = null,
        ?Closure $now = null,
        bool $secure = false,
        array $fallbackCookies = [],
    ): array {
        $header = self::headerReader($headers);
        $context = [];

        $ip = self::clientIp($header, $clientAddress, $trustProxy);
        if ($ip !== null) {
            $context['ip'] = $ip;
        }
        $userAgent = $header('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $context['userAgent'] = $userAgent;
        }
        $locale = trim(explode(';', explode(',', $header('accept-language') ?? '')[0])[0]);
        if ($locale !== '' && $locale !== '*') {
            $context['locale'] = $locale;
        }

        $referrer = $header('referer');
        $referrer = $referrer === '' ? null : $referrer;
        $page = self::parseUrl($pageUrl) ?? self::absoluteUrl($url, $header, $trustProxy, $secure);
        if ($page !== null || $referrer !== null) {
            $context['page'] = array_merge(
                $page !== null ? ['url' => $page['href'], 'path' => $page['path'], 'search' => $page['search']] : [],
                $referrer !== null ? ['referrer' => $referrer] : [],
            );
        }

        // Landing parameters: from the page URL, else the referrer (an API call from the landing page).
        $source = $page !== null && $page['search'] !== '' ? $page : self::parseUrl($referrer);
        $query = $source !== null ? self::parseQuery($source['query']) : [];

        $campaign = [];
        foreach (self::UTM as $param => $key) {
            if (($query[$param] ?? '') !== '') {
                $campaign[$key] = $query[$param];
            }
        }
        foreach (ClientIds::CAMPAIGN_PARAMS as $param) {
            if (($query[$param] ?? '') !== '') {
                $campaign[$param] = $query[$param];
            }
        }
        if ($campaign !== []) {
            $context['campaign'] = $campaign;
        }

        $cookies = self::cookies($headers, $fallbackCookies);
        $clientIds = ClientIds::fromCookies(static fn (string $name): ?string => ($cookies[$name] ?? '') !== '' ? $cookies[$name] : null);
        foreach (ClientIds::URL_CLICK_IDS as $param => $key) {
            if (($query[$param] ?? '') !== '') {
                $clientIds[$key] = $query[$param];
            }
        }
        // A landing fbclid is a click happening now: it beats an older `_fbc` cookie, unless the
        // cookie already records this same click.
        $fbclid = $query['fbclid'] ?? '';
        if ($fbclid !== '' && ! str_ends_with($clientIds['fbc'] ?? '', '.'.$fbclid)) {
            $clientIds['fbc'] = ClientIds::fbcFromFbclid($fbclid, $now !== null ? $now() : (int) floor(microtime(true) * 1000));
        }
        if ($clientIds !== []) {
            $context['clientIds'] = $clientIds;
        }

        return $context;
    }

    /**
     * Parses a Cookie header like `parseCookies` in context.ts: split on `;`, surrounding quotes
     * stripped, percent-decoded (the raw value is kept when it is not valid percent-encoding),
     * first occurrence wins.
     *
     * @return array<string, string>
     */
    public static function parseCookies(?string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header ?? '') as $part) {
            $eq = strpos($part, '=');
            if ($eq === false || $eq < 1) {
                continue;
            }
            $name = trim(substr($part, 0, $eq));
            $value = trim(substr($part, $eq + 1));
            if ($name === '') {
                continue;
            }
            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            $value = self::decodeComponent($value);
            if (! array_key_exists($name, $cookies)) {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * `decodeURIComponent` semantics: a malformed escape or invalid UTF-8 keeps the raw value.
     */
    private static function decodeComponent(string $value): string
    {
        if (! str_contains($value, '%')) {
            return $value;
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            return $value;
        }
        $decoded = rawurldecode($value);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $value;
    }

    /**
     * @param  array<string, string|list<string|null>|null>  $headers
     * @param  array<string, string>  $fallbackCookies
     * @return array<string, string>
     */
    private static function cookies(array $headers, array $fallbackCookies): array
    {
        $raw = self::headerReader($headers)('cookie');

        // Without a Cookie header (synthetic requests, e.g. in tests) use the cookie bag as given.
        return $raw !== null && $raw !== '' ? self::parseCookies($raw) : $fallbackCookies;
    }

    /**
     * @return array<string, string>
     */
    private static function cookieBag(Request $request): array
    {
        $cookies = [];
        foreach ($request->cookies->all() as $name => $value) {
            if (is_string($value)) {
                $cookies[(string) $name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * @param  array<string, string|list<string|null>|null>  $headers
     * @return Closure(string): ?string
     */
    private static function headerReader(array $headers): Closure
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', array_filter($value, static fn ($v): bool => $v !== null)) : $value;
            if ($value !== null) {
                $normalised[strtolower((string) $name)] ??= $value;
            }
        }

        return static fn (string $name): ?string => $normalised[strtolower($name)] ?? null;
    }

    /**
     * `::ffff:203.0.113.7` (an IPv4 client on a dual-stack socket) -> `203.0.113.7`.
     */
    private static function normaliseIp(?string $ip): ?string
    {
        $ip = trim($ip ?? '');
        if ($ip === '') {
            return null;
        }

        return str_starts_with($ip, '::ffff:') && str_contains($ip, '.') ? substr($ip, 7) : $ip;
    }

    /**
     * @param  Closure(string): ?string  $header
     */
    private static function clientIp(Closure $header, ?string $clientAddress, bool $trustProxy): ?string
    {
        if ($trustProxy) {
            // The left-most entry is the original client; each proxy appends the address it saw.
            $forwarded = explode(',', $header('x-forwarded-for') ?? '')[0];
            $ip = self::normaliseIp($forwarded) ?? self::normaliseIp($header('x-real-ip'));
            if ($ip !== null) {
                return $ip;
            }
        }

        return self::normaliseIp($clientAddress);
    }

    /**
     * @param  Closure(string): ?string  $header
     * @return array{href: string, path: string, search: string, query: string}|null
     */
    private static function absoluteUrl(?string $raw, Closure $header, bool $trustProxy, bool $secure): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $raw) === 1) {
            return self::parseUrl($raw);
        }

        // A request URL is a path: rebuild the origin from the Host header.
        $host = ($trustProxy ? $header('x-forwarded-host') : null) ?? $header('host');
        if ($host === null || $host === '') {
            return null;
        }
        $forwardedProto = $trustProxy ? trim(explode(',', $header('x-forwarded-proto') ?? '')[0]) : '';
        $proto = $forwardedProto !== '' ? $forwardedProto : ($secure ? 'https' : 'http');

        return self::parseUrl($proto.'://'.trim(explode(',', $host)[0]).(str_starts_with($raw, '/') ? '' : '/').$raw);
    }

    /**
     * A WHATWG-like parse of an absolute URL: lower-cased scheme and host, default port dropped,
     * `/` for an empty path. Null when it is not an absolute URL with a valid host.
     *
     * @return array{href: string, path: string, search: string, query: string}|null
     */
    private static function parseUrl(?string $value): ?array
    {
        if ($value === null || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $value) !== 1) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9._~-]+)$/', $host) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && (self::DEFAULT_PORTS[$scheme] ?? null) === $port) {
            $port = null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $query = $parts['query'] ?? '';
        $search = $query !== '' ? '?'.$query : '';
        $userInfo = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';

        $href = $scheme.'://'.$userInfo.$host.($port !== null ? ':'.$port : '').$path.$search
            .(isset($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '');

        return ['href' => $href, 'path' => $path, 'search' => $search, 'query' => $query];
    }

    /**
     * `URLSearchParams` semantics: `+` is a space, percent-decoded, first occurrence wins, keys
     * kept verbatim (unlike `parse_str`, which mangles dots and brackets).
     *
     * @return array<string, string>
     */
    private static function parseQuery(string $query): array
    {
        $params = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (! array_key_exists($key, $params)) {
                $params[$key] = urldecode($value);
            }
        }

        return $params;
    }

    private function __construct() {}
}
