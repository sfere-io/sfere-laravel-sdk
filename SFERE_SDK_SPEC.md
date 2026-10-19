# Build Instructions: `fanfinity/sfere-laravel`, the Sfere server SDK for Laravel

You are a coding agent. Build a production-quality Laravel package that sends analytics events from a Laravel backend to **Sfere ingestion**. It is the PHP sibling of `@fanfinity/sfere-node`, and the events it sends must be identical on the wire to that SDK's.

Read this entire file before writing code. Work through the phases in order. Do not start a phase until the previous phase's acceptance criteria pass.

---

## 0. Ground rules

1. **The wire contract is the source of truth.** It lives in the `fanfinity/sfere-sdk` repo:
   - `docs/WIRE-CONTRACT.md`: endpoints, auth, envelope, context fields, delivery, responses.
   - `docs/EVENTS.md`: event names and properties.
   - `docs/HTTP-API.md`: curl examples.
   - `packages/node/src/context.ts` and `packages/shared/src/index.ts`: the reference implementation of request context and click IDs.

   "A new SDK is done when its events match this page." If this spec and the contract disagree, the contract wins: follow it and record the difference in `NOTES.md`. **Do not use jitsu.com's public docs.** Sfere runs a fork whose behaviour differs from them. §2 summarises the facts this SDK depends on, checked against the fork's ingest code.
2. **Do not invent endpoints, fields or headers.** If something is unclear, implement the minimal contract behaviour and leave a `// TODO(verify):` comment. Every field you add becomes a warehouse column (the events table has already hit 300+ columns once).
3. **Never break the host application.** By default, analytics failures never throw into a user's request. Log them instead.
4. **Check current Laravel and PHP versions** before setting constraints in `composer.json`. Support every Laravel major that still receives security fixes on the day you build this.
5. Keep the public API small, typed and documented. Prefer clarity over cleverness.
6. **Describe the platform generically in public text.** The README, code comments, exception messages and log lines say "Sfere ingestion", never the underlying vendor (WIRE-CONTRACT §7).
7. Commit after each phase with a clear message.

---

## 1. Package identity

| Item | Value |
|---|---|
| Composer name | `fanfinity/sfere-laravel` |
| Root namespace | `Sfere\` (PSR-4 → `src/`) |
| Facade | `Sfere` |
| Config file | `config/sfere.php` (publish tag: `sfere-config`) |
| Service provider | `Sfere\SfereServiceProvider` (auto-discovered) |
| `context.library` | `{ "name": "@fanfinity/sfere-laravel", "version": "<package version>", "base": "laravel/framework@<app()->version()>" }` |
| User-Agent header | `@fanfinity/sfere-laravel/<package version>` |
| License | MIT. Note: `@fanfinity/sfere-node` is currently `UNLICENSED`, so confirm the license with the owner before publishing |
| PHP | 8.2+ (adjust after the version check in rule 4) |

**Why a native implementation.** The SDK is built on Laravel's `Http` client, not on Segment's `analytics-php`. That library's consumers (socket, fork-curl) bypass `Http::fake()`, and they have no hook to reshape the wire format. The `base` value records the framework version so you can debug it in the warehouse.

**Owner action, outside this repo.** Add the `@fanfinity/sfere-laravel` package name and its `library.name` to WIRE-CONTRACT §7 and to the "Server" column of §4.

---

## 2. Ingestion facts (Sfere fork, verified in code)

**Host.** The tenant's ingestion origin: scheme + host, with no path, e.g. `https://ingest.example.com`. Reject a host that has a path, query or fragment, as `@fanfinity/sfere-node` does (`parseHost`). With an invalid host the SDK becomes a no-op and logs one warning.

**Endpoints this SDK uses:**

| Mode | Request |
|---|---|
| `sync` | `POST {host}/api/s/s2s/{type}`. `{type}` is `track`, `identify`, `page`, `group` or `alias` |
| `batch`, `queue` | `POST {host}/v1/batch`, with body `{ "batch": [...], "writeKey": "<key>", "sentAt": "<ISO-8601>" }` |

- Do not use the envelope-level `context`. Ingest merges it shallowly, with event keys winning at the top level. Each event carries its own complete `context` instead.
- `screen` exists, but it is for mobile only. Do not expose it.

**Auth.** Write key format is `keyId:secret`.
- Always send `Authorization: Basic base64("<keyId:secret>:")`: the key is the username and the password is empty.
- On `/v1/batch`, also put the key in the body as `writeKey`.
- Do **not** offer query-string auth (`?writekey=`), because the key ends up in proxy and access logs.
- `X-Write-Key` is accepted by ingest, but the SDK does not need it.

**Key type decides ingest type, not the path.**
- A *server* (private) key is treated as s2s on every path. Ingest then keeps the `context.ip` and `context.userAgent` the SDK sends, and **never fills them in**.
- A *browser* (public) key on the same paths is accepted. The event is then handled as a browser event: `context.ip` is overwritten with the caller's IP, which here is the Laravel server. That silently corrupts every event.
- The SDK must be configured with a server key. See §6 and §8 for how to warn about this.

**Request rules:**
- `Content-Type: application/json`.
- `Content-Encoding: gzip` is decoded on the batch path only. The single-event path returns 400 for a gzipped body.
- At most 100 events and 500 KB per batch request.
- Each event is checked against a 1 MB limit after ingest wraps it.

**Fields ingest stamps:**
- `receivedAt` is always overwritten.
- `timestamp` and `messageId` are set only if missing.
- `requestIp` is always set at the top level to *the caller's* IP, which is your server's.
- On batch, `sentAt` is used to correct clock skew of 5 minutes or more. The single path ignores it.

**Responses (server key):**

| Situation | Response | SDK action |
|---|---|---|
| Single, success | `200 {"ok":true}` | success |
| Single, stream has no destinations | `200 {"error":"no destinations found for stream: …"}` | **failure**, do not retry. The log says "no destinations configured for this stream" |
| Batch | `200 {"ok":true,"receivedEvents":n,"okEvents":n,"errors":[…]?}` | `okEvents < receivedEvents` is a **partial failure**. Each entry of `errors[]` reads `"Message ID: <id>: <reason>"`. Do not retry those events; report them in `DeliveryFailed` |
| Malformed JSON, or `track` without `event` | `400 {"error":…}` | failure, no retry |
| Unknown or revoked key | `401 {"error":"stream not found: …"}` | failure, no retry. Log "invalid write key" (never log the key itself) |
| Throttled | `402` | failure, no retry. Log it loudly |
| Rate limited | `429` | retry with backoff, honour `Retry-After` |
| Server error | `5xx` | retry with backoff |
| Network or connection error | — | retry with backoff |

**General rule:** treat any 2xx whose JSON body does not have `ok === true` as a failure. The Salla and Zid relays in the console do the same.

**Dedup.** Ingest does not dedupe by `messageId`, but the warehouse does (primary key `message_id`). Every server-side ad destination sends `messageId` as the platform's `event_id`. That has two consequences:
- Retrying with the same `messageId` is safe.
- To dedupe a conversion between the browser pixel and the server, both must carry the **same `messageId`**. The SDK must therefore let callers set `messageId`, and it must return the `messageId` it used.

**Tenancy.** The write key selects the stream. Do **not** add any account, tenant or store field to events.

---

## 3. Event envelope

These fields are allowed on the wire (WIRE-CONTRACT §3):
- `type`, `messageId`, `userId`, `anonymousId`, `timestamp`, `context`.
- `event` and `properties` on `track`.
- `name` and `properties` on `page`.
- `traits` on `identify` and `group`.
- `groupId` on `group`.
- `previousId` on `alias`.
- `integrations`, **only** when the caller set a non-empty value.

Never send `_metadata`, null-valued envelope keys, or any field not listed above. `sentAt` goes on the batch envelope only, never on the individual events.

**Rules:**
- **`messageId`:** generated with `Str::uuid()` (36 characters) unless the caller passes one. A caller value must match `^[A-Za-z0-9._-]{1,64}$`. If it doesn't, treat it as invalid input (§3.1). Do not silently rewrite it.
- **`timestamp`:** ISO-8601 with milliseconds and a UTC offset, the time the event happened. Defaults to now.
- **`userId`:**
  - Always a string. Cast `getAuthIdentifier()` results.
  - At most 512 characters.
  - Must be the app's stable, opaque, non-PII internal id.
  - Reject the placeholder values `0`, `-1`, `null`, `undefined`, `test`, `na` and `n/a` (case-insensitive), and the empty string.
- At least one of `userId` or `anonymousId` is required.
- **`track.event`:** required, at most 128 characters. Use the GA4 names from `EVENTS.md` (§3.2).
- **`track` properties:** always carry `properties.sfere_dedup_id`. Keep the caller's value; otherwise use the event's `messageId`.
- **`group`:** `groupId` is required.
- **`alias`:** `previousId` and `userId` are both required.
- **Traits** (identify and `context.traits`): plain values. **No hashing and no phone reformatting.** Destinations do both, per destination.
- **Size:** one serialised event must stay under 900 KB, leaving room for ingest's wrapping. A larger event is treated as invalid input.

### 3.1 Invalid input
On invalid input, throw `Sfere\Exceptions\InvalidEventException` **only** if `throw_exceptions` is true. Otherwise log a warning with the reason and the event type (no PII) and drop the event.

### 3.2 Event names
Ship `Sfere\Ecommerce\Event`, a class of string constants for the GA4 names in `EVENTS.md`:
- `VIEW_ITEM`, `VIEW_ITEM_LIST`, `SELECT_ITEM`.
- `ADD_TO_CART`, `REMOVE_FROM_CART`, `ADD_TO_WISHLIST`, `VIEW_CART`.
- `BEGIN_CHECKOUT`, `ADD_SHIPPING_INFO`, `ADD_PAYMENT_INFO`.
- `PURCHASE`, `REFUND`.
- `SEARCH`, `SIGN_UP`, `LOGIN`, `GENERATE_LEAD`.

Do not enforce the names. Any other name is forwarded unchanged (EVENTS.md: "Any other name is accepted and forwarded as-is"). All README examples use GA4 names and properties (`transaction_id`, `currency` such as `SAR`, numeric `value`, `items[]` with `item_id` and `item_name`).

---

## 4. Sfere context (the part that makes server events useful)

Ad-platform conversion APIs match on the end user's IP, User-Agent and click IDs. Ingest never fills these in for server events. A server event without them reaches Meta, TikTok and the others with **no IP at all** (verified on staging 2026-09-29). Port `requestContext()` from `packages/node/src/context.ts` exactly.

### 4.1 `Sfere::requestContext(?Request $request = null, ?string $pageUrl = null): array`
This is a public helper. It defaults to the current request and returns only the keys it could fill.

| Field | Source |
|---|---|
| `ip` | If `context.trust_proxy` is true (the default): the left-most `X-Forwarded-For` entry, then `X-Real-IP`, then `REMOTE_ADDR`. If false: `$request->ip()`, which follows the app's own TrustProxies config. Map `::ffff:a.b.c.d` to `a.b.c.d` |
| `userAgent` | `User-Agent` header |
| `locale` | First `Accept-Language` tag, without its q-value. Skip `*` |
| `page` | `{url, path, search}` from `$pageUrl`, otherwise the full request URL. Add `referrer` from the `Referer` header. `search` includes the leading `?`, or is an empty string |
| `campaign` | From the page URL's query, otherwise the referrer's query. Mapping: `utm_source→source`, `utm_medium→medium`, `utm_campaign→name`, `utm_term→term`, `utm_content→content`, plus `gad_source` and `gad_campaignid` under their own names. Only keys that are present; never invent any |
| `clientIds` | Port `packages/shared/src/index.ts` exactly, see below |

**`clientIds`, from cookies:**
- `_fbp→fbp`, `_fbc→fbc`, `_ttp→ttp`, `ttclid→ttclid`, `_scid→scid`, `_scclid→scclid`, `_epik→epik`, `li_fat_id→li_fat_id`, `_uetmsclkid→msclkid`, `__oppref→oppref`, `__obref→obref`.
- `gclid`: from `_gcl_aw`, otherwise `_gcl_dc`. The value looks like `GCL.<ts>.<gclid>`; take everything after the second dot.
- `clarity_uid` from `_clck` and `clarity_sid` from `_clsk`: the first field, split on `^` or `|`.

**`clientIds`, from URL params:**
- `gclid`, `wbraid`, `gbraid`, `ttclid`, `ScCid→scclid`, `twclid`, `epik`, `msclkid`, `li_fat_id`, `oppref`, `rdt_cid`.
- A landing `fbclid` becomes `fbc = "fb.1.<nowMs>.<fbclid>"`, unless the existing `fbc` already ends in `.<fbclid>`.

⚠️ **Read cookies by parsing the raw `Cookie` header** (`$request->headers->get('cookie')`), with the same rules as `parseCookies` in context.ts:
- Split on `;`.
- Strip surrounding quotes.
- `rawurldecode`, keeping the raw value if decoding fails.
- First occurrence wins.

**Never use `$request->cookie()`.** Laravel's `EncryptCookies` middleware turns every cookie it did not encrypt (`_fbp`, `_gcl_aw`, `ajs_anonymous_id`, …) into `null` in `$request->cookies`. Using it would silently drop every click ID. Add a test that runs with `EncryptCookies` active.

### 4.2 Anonymous ID from the browser
- If an event has no `anonymousId` and `context.anonymous_id_cookie` is set (default `ajs_anonymous_id`), read that cookie from the raw header and use it as `anonymousId`.
- The browser SDK may store the value JSON-quoted (`"…"` or `%22…%22`); unwrap it.
- This lets profiles merge server events with browser sessions. Profile merging joins on `anonymous_id` plus email or phone.
- **Owner action:** this is not yet in WIRE-CONTRACT §4. Add it there; `@fanfinity/sfere-node` lacks it today. Until then, document the setting as experimental.

### 4.3 When the context is captured
**Context is captured when the event is created, not when it is sent.**
- In `queue` mode the job runs in a worker with no request.
- In `batch` mode the flush may run after the request is gone.

Build the full context inside `track()` and its siblings, and store it on the buffered event.

**Merge order**, later wins. Associative arrays (`page`, `campaign`, `clientIds`, `traits`, `consent`, `session`) are merged recursively; lists are replaced.

1. `context.static` from config.
2. `requestContext()`, when `context.include_request` is true and an HTTP request exists. Not in the console, and not inside a queue job.
3. Traits from the trait resolver (§5), set as `context.traits`.
4. `Sfere::withContext([...])` scoped context.
5. The per-call `context:` argument.
6. `library`: always the SDK's own. Callers cannot override it.

**Events with no customer request** (Zid or Salla webhooks, cron jobs, a refund issued by staff): the README must show how to save `Sfere::requestContext()` at checkout and pass it back later with `context:`, as in `packages/node/README.md`.

**Privacy.** `include_request` defaults to **true**. If an app turns it off, the SDK logs one warning per process: server events will reach ad destinations without IP, User-Agent or click IDs.

**Browser-only fields.** `context.session.id` (a number, epoch ms) and `context.consent.categoryPreferences` live in the browser's localStorage, which the server cannot read. Accept them only when the caller passes them; never invent them.

---

## 5. Public API

```php
use Sfere\Facades\Sfere;
use Sfere\Ecommerce\Event;

// Identify
Sfere::identify('10293', ['email' => 'fan@example.com', 'phone' => '+966500000000']);

// Track: returns the messageId used, so it can be given to a browser pixel as event_id
$messageId = Sfere::track(Event::PURCHASE, [
    'transaction_id' => 'order_98765',
    'currency'       => 'SAR',
    'value'          => 256.96,
    'items'          => [['item_id' => 'sku_12345', 'item_name' => 'Away Jersey', 'price' => 89.99, 'quantity' => 2]],
], userId: '10293', messageId: 'purchase-order_98765');

// Page (server-side page view)
Sfere::page('Pricing', ['url' => 'https://example.com/pricing'], userId: '10293');

// Group / alias
Sfere::group('company_42', ['name' => 'Acme'], userId: '10293');
Sfere::alias(previousId: $anonymousId, userId: '10293');

// The authenticated user (userId from the resolver; traits into context.traits when a resolver is set)
Sfere::forUser(auth()->user())->track(Event::SIGN_UP, ['method' => 'email']);

// Anonymous visitor
Sfere::anonymous($anonymousId)->track(Event::BEGIN_CHECKOUT, [...]);

// Context for an event outside a customer request (webhook, cron)
$ctx = Sfere::requestContext();                      // at checkout: store it with the order
Sfere::track(Event::PURCHASE, $props, userId: '10293', context: $order->sfere_context);

// Overrides
Sfere::track('import_completed', [], userId: 'u1', timestamp: now()->subDay(), context: ['source' => 'csv']);

// Send everything buffered now
Sfere::flush();
```

**Fluent builder** for advanced cases. `send()` returns the messageId.

```php
Sfere::event('track')
    ->name(Event::ADD_TO_CART)
    ->userId('10293')
    ->messageId('cart-abc-1')
    ->properties(['currency' => 'SAR', 'value' => 89.99, 'items' => [...]])
    ->context(['campaign' => ['source' => 'newsletter']])
    ->send();
```

**Requirements:**
- `track`, `identify`, `page`, `group` and `alias` accept the named arguments `userId`, `anonymousId`, `timestamp` (`DateTimeInterface`), `context` (array), `messageId` (string) and `integrations` (array).
- **Return values:** `track()` and the builder's `send()` return the `messageId`. They return it even when the SDK is disabled or faked, so calling code never branches on it.
- **`forUser()`:**
  - Accepts any `Authenticatable`, or `null` (null means the call is anonymous and only the cookie anonymousId applies).
  - The id comes from `getAuthIdentifier()`, cast to string.
  - `Sfere::resolveUserIdUsing(fn ($user) => $user->uuid)` customises the id.
  - `Sfere::resolveTraitsUsing(fn ($user) => ['email' => …, 'phone' => …])` fills `context.traits` on every event for that user. There is **no default trait resolver**, because sending PII is the app's decision.
- **`withContext()` and `forUser()`** return an immutable scoped client. Do not mutate the singleton; Octane runs many requests through one process.
- **Speed:** calls must return quickly. The delivery mode (§6) decides when the HTTP request happens.

---

## 6. Delivery modes

Set with `SFERE_DELIVERY`.

1. **`sync`:** one `POST /api/s/s2s/{type}` per event, inside the call. Simplest; meant for the console and debugging. Retries block the caller, and the README must say so.
2. **`batch`** (default): buffer events in memory and send them to `/v1/batch`.
   - Flush when the buffer reaches `batch.max_size` (default and maximum 100).
   - Flush when the oldest buffered event is older than `batch.max_wait_seconds` (default 10, checked on each enqueue). This is for long-running commands and daemons.
   - Flush at the end of the lifecycle: `app()->terminating(...)`, `JobProcessed`, `JobFailed`, console `CommandFinished` and Octane `RequestTerminated`.
   - Split a flush into requests of at most 100 events and at most `batch.max_bytes` (default and maximum 500 000 bytes of JSON).
3. **`queue`:** dispatch a `Sfere\Jobs\SendEvents` job carrying the fully built events (context already captured, §4.3) on a configurable connection and queue. The job uses the same transport.
   - **Retries:** HTTP-level retries happen inside one attempt. Retryable failures that remain then fail the job, so Laravel's queue retry applies, with `tries` and `backoff` from config.
   - **Safe to retry:** the messageIds are fixed when the job is created, so a retried job does not create duplicates (the warehouse dedupes on `message_id`).

**Octane and long-running safety:**
- The buffer must not leak between requests: it is flushed and reset on `RequestTerminated`.
- Do not keep per-request state in static properties.
- Scoped clients (`forUser`, `withContext`) are new objects.

---

## 7. Transport and reliability

- **Client:** build on Laravel's `Http` client so users can test with `Http::fake()`. Put it behind `Sfere\Contracts\Transport`.
- **Request settings:**
  - Timeout 3 s and connect timeout 2 s, both configurable.
  - Headers: Basic auth, `Content-Type: application/json`, and the `User-Agent` from §1.
- **Retry policy:**
  - Retry only network errors, 429 and 5xx.
  - Exponential backoff with full jitter, default 3 attempts. Honour `Retry-After` (seconds or HTTP date), capped at `http.max_retry_after` (default 10 s).
  - **Never retry any other 4xx, including 402.**
- **Response handling:** classify every response as in the §2 table, including the 200-with-`error` and partial-batch cases.
- **gzip:** `http.compression` (default `false`) gzips **only** `/v1/batch` bodies and sets `Content-Encoding: gzip`. Never gzip the single-event path, which returns 400.
- **On failure:**
  - Log to the configured channel. Include the event count, the failed messageIds, the HTTP status and the error. Never include the write key or event payloads, which may hold PII.
  - Fire `Sfere\Events\DeliveryFailed` with `messageIds`, `status`, `reason` and `retryable`.
- **On success:** fire `Sfere\Events\EventsSent` with `messageIds` and `okEvents`.
- **No other hosts:** never contact any host except `{host}`. No remote settings, telemetry or metrics (WIRE-CONTRACT §5).

---

## 8. Configuration (`config/sfere.php`)

```php
return [
    'enabled'          => env('SFERE_ENABLED', true),
    'host'             => env('SFERE_HOST'),             // ingestion origin, no path
    'write_key'        => env('SFERE_WRITE_KEY'),        // SERVER key, keyId:secret

    'delivery'         => env('SFERE_DELIVERY', 'batch'), // sync | batch | queue
    'batch' => [
        'max_size'         => 100,     // capped at 100
        'max_bytes'        => 500_000, // capped at 500 KB
        'max_wait_seconds' => 10,
    ],
    'queue' => [
        'connection' => env('SFERE_QUEUE_CONNECTION'),
        'name'       => env('SFERE_QUEUE', 'default'),
        'tries'      => 5,
        'backoff'    => [10, 30, 60, 120],
    ],

    'http' => [
        'timeout'         => 3,
        'connect_timeout' => 2,
        'retries'         => 3,
        'max_retry_after' => 10,
        'compression'     => false, // gzip /v1/batch only
    ],

    'context' => [
        'include_request'     => true,               // ip, userAgent, locale, page, campaign, clientIds
        'trust_proxy'         => true,               // left-most X-Forwarded-For; false = $request->ip()
        'anonymous_id_cookie' => 'ajs_anonymous_id', // null to disable (experimental, §4.2)
        'static'              => [],                 // merged into every event's context
    ],

    'throw_exceptions' => env('SFERE_THROW', false),
    'log_channel'      => env('SFERE_LOG_CHANNEL'),
];
```

**Behaviour rules:**
- **No-op mode:** if `enabled` is false, or `host` or `write_key` is missing or invalid, the SDK becomes a no-op and logs **one** warning per process, not one per event.
  - An invalid `host` has a path, query or fragment, or a scheme other than http(s).
  - An invalid `write_key` has no `:`, which means it is not a `keyId:secret` key.
- **Not configurable:** there is no `endpoint` or `auth_method` setting. The key type decides the ingest type, and the SDK always uses Basic auth.
- **Server key only:** the SDK cannot tell a browser key from a server key by the response. Say prominently in the README and in `sfere:install` that it needs a **server** key, and describe the browser-key symptom: every event's IP becomes your server's.

---

## 9. Testing support for users (`Sfere::fake()`)

Follow the pattern of Laravel's `Event::fake()`:

```php
Sfere::fake();

// ... run app code ...

Sfere::assertTracked(Event::PURCHASE);
Sfere::assertTracked(Event::PURCHASE, fn (array $event) => $event['properties']['value'] === 256.96);
Sfere::assertIdentified('10293');
Sfere::assertNotTracked(Event::REFUND);
Sfere::assertNothingSent();
Sfere::assertSentCount(3);
```

- The fake records the **fully built** events (the exact arrays that would go on the wire, context included) and makes no HTTP calls.
- `track()` still returns a messageId.

---

## 10. Extras

- **`sfere:test`** sends a real `track` (`event: "sfere_sdk_test"`) through `sync` to the configured host. It prints success, or the classified error from §2 (invalid key, no destinations, network error, …). It also reminds the user that a browser key is accepted but corrupts the IP.
- **`sfere:install`** publishes the config and prints the env vars to add (`SFERE_HOST`, `SFERE_WRITE_KEY`), with the server-key warning.
- **Middleware `Sfere\Http\Middleware\TrackPageViews`** (optional):
  - Sends a `page` event for GET requests that return a 2xx HTML response.
  - Has an exclude list (`/horizon*`, `/telescope*`, `/livewire*`, `/_debugbar*`).
  - Uses the cookie anonymousId when there is no user.
  - Docs must warn that it **double-counts** page views if the browser SDK is also installed.
- **No Blade directive.** The browser SDK (`@fanfinity/sfere-sdk`) is installed by its own snippet; this package does not render trackers.

---

## 11. Directory layout

```
sfere-laravel/
├── composer.json
├── config/sfere.php
├── src/
│   ├── SfereServiceProvider.php
│   ├── Sfere.php                  # main client (singleton); scoped clients are new objects
│   ├── Facades/Sfere.php
│   ├── Contracts/Transport.php
│   ├── Transport/HttpTransport.php
│   ├── Transport/ResponseClassifier.php
│   ├── Buffer/EventBuffer.php
│   ├── Context/RequestContext.php  # port of packages/node/src/context.ts
│   ├── Context/ClientIds.php       # port of packages/shared/src/index.ts
│   ├── Ecommerce/Event.php         # GA4 name constants
│   ├── Events/                     # EventsSent, DeliveryFailed
│   ├── Payload/                    # EventBuilder, Validator, wire shaping
│   ├── Jobs/SendEvents.php
│   ├── Console/                    # TestCommand, InstallCommand
│   ├── Http/Middleware/TrackPageViews.php
│   ├── Testing/SfereFake.php
│   └── Exceptions/
├── tests/
│   ├── TestCase.php               # Orchestra Testbench
│   ├── Fixtures/                  # golden wire payloads, see §12
│   ├── Unit/
│   └── Feature/
├── .github/workflows/tests.yml
├── phpstan.neon
├── pint.json
├── README.md
├── CHANGELOG.md
├── NOTES.md                       # contract checks and deviations
└── LICENSE.md
```

Keep the `Context/` and `Payload/` classes free of facades, using plain inputs (headers array, URL, clock). That way a framework-agnostic PHP core could be extracted later, for example for the Symfony `apis` service, without a rewrite.

---

## 12. Quality bar

**Tests:** Pest or PHPUnit with `orchestra/testbench`. Use `Http::fake()` and `Http::assertSent()` to check exact URLs, headers and JSON bodies. At minimum, cover:

- **Wire shape:**
  - Every event type builds the correct payload: `messageId`, `timestamp`, the `library` stamp, `sfere_dedup_id` on `track`.
  - No `_metadata`; no `integrations` unless set; no `sentAt` on events; `writeKey` and `sentAt` on the batch envelope.
- **Context:**
  - Port the cases in `packages/node/test/unit/context.test.ts` one for one: IP precedence and the `::ffff:` mapping, `trust_proxy` off, page and referrer, campaign falling back to the referrer, every cookie and URL click ID, `_gcl_aw` parsing, Clarity parsing, `fbclid→fbc` and its "same click" exception.
  - Cookies are still read while `EncryptCookies` is active.
  - Context is captured at call time: queue mode serialises it, and a batch flush after the request still carries it.
  - The merge order from §4.3.
- **Validation:**
  - Caller `messageId` regex.
  - Forbidden userIds; integer ids cast to string.
  - `track` without a name, or a name over 128 characters.
  - Missing both `userId` and `anonymousId`.
  - An event over the size limit.
- **Transport:**
  - Basic auth header format.
  - Retries on 429, 5xx and connection errors, honouring `Retry-After`; no retry on 400, 401, 402 or 403.
  - A 200 with an `error` body is a failure.
  - A partial batch (`okEvents < receivedEvents`) reports the failed messageIds.
  - gzip only on batch.
- **Delivery:**
  - Batch flushes at `max_size`, at `max_wait_seconds`, at the end of the lifecycle, and on Octane `RequestTerminated`.
  - Batches split on `max_bytes`.
  - Queue mode dispatches the job (`Queue::fake()`).
- **Behaviour:**
  - Failures do not throw when `throw_exceptions` is false, and do throw when it is true.
  - No-op when disabled or misconfigured, with exactly one warning.
  - `forUser()`, the custom id resolver and the trait resolver.
  - The `Sfere::fake()` assertions themselves.
- **Conformance fixtures:**
  - `tests/Fixtures/*.json` hold golden payloads for one event of each type. Build them from the `HTTP-API.md` examples and from `@fanfinity/sfere-node` output for the same input.
  - The test compares SDK output to them, ignoring `messageId`, `timestamp` and `sentAt` when generated, and `library.version` and `library.base`.

**Other bars:**
- **Static analysis:** Larastan/PHPStan level 8, zero errors.
- **Style:** Laravel Pint, default preset.
- **CI:** GitHub Actions matrix across supported PHP and Laravel versions, running tests, PHPStan and Pint.
- **Dependencies:** none beyond `illuminate/*`. Use `Str::uuid()`, not `ramsey/uuid` directly.

---

## 13. Phases and acceptance criteria

**Phase 1: Skeleton.** Composer package, service provider, config publishing, facade, Testbench setup, CI pipeline.
✅ `composer test`, PHPStan and Pint pass on an empty test suite. The package installs into a fresh Laravel app through a path repository and is auto-discovered.

**Phase 2: Core client, context, sync delivery.** Payload builders for track, identify, page, group and alias. Validation, wire shaping, `RequestContext` and `ClientIds` ports. `HttpTransport` with Basic auth, retries and response classification. `forUser()` and `anonymous()`, the id and trait resolvers, and `withContext()`.
✅ All payload, context and transport tests pass, and the conformance fixtures match. `php artisan sfere:test` delivers an event to a **staging** Sfere stream using a server key. The event shows up in that stream's live events with the expected `context.ip`. Record in `NOTES.md` how you tested.

**Phase 3: Batch and queue delivery.** `EventBuffer`, lifecycle and time-based flushing, Octane safety, `/v1/batch` with partial-failure handling, the `SendEvents` job.
✅ Batch and queue tests pass. No events are lost when a request ends. The buffer is empty at the start of every Octane request.

**Phase 4: Testing fake and Laravel events.** `Sfere::fake()` with all its assertions; `EventsSent` and `DeliveryFailed`.
✅ Fake tests pass, and the README shows how to use the fake.

**Phase 5: Extras and docs.** `sfere:install`, the optional middleware, and the full README.
✅ The README covers:
- Installation, and getting a **server** key.
- Configuration and every public method.
- GA4 event names and the ecommerce examples from `EVENTS.md`.
- Delivery modes and request context.
- Storing context for webhooks and cron.
- Browser and server dedup via `messageId`, using the pattern in `INTEGRATION.md` "Deduplication", from the server side: render the returned messageId into the page for the pixel's `eventID`.
- Testing, privacy notes and troubleshooting: 401, "no destinations", events showing the server IP (a browser key), missing click IDs (`EncryptCookies`, or a proxy stripping cookies).

`CHANGELOG.md` has a `1.0.0` entry.

---

## 14. Non-goals (do not build)

- A browser tracker or a Blade snippet. Use `@fanfinity/sfere-sdk`.
- Reading data back from Sfere, or managing streams and keys through any management API.
- Destination-specific logic (Meta, GA4, TikTok, …), hashing or phone normalisation. Destinations do this.
- A persistent disk or database queue. Rely on Laravel queues for durability.
- `screen` events (mobile only).

---

## 15. Prerequisites and decisions outside this repo (owner, not the coding agent)

1. **Contract update** in `fanfinity/sfere-sdk`:
   - Add `@fanfinity/sfere-laravel` to WIRE-CONTRACT §7 and to the §4 Server column.
   - Add the `anonymousId`-from-cookie rule (§4.2), and port it to `@fanfinity/sfere-node` so both server SDKs behave the same.
2. **Server keys for Laravel merchants:**
   - Today a merchant mints one with `POST /v1/accounts/{account_id}/sources/{source_id}/write-keys` and `{ "kind": "private" }`.
   - This is allowed only on `web` sources (`backend/app/services/sources.py:149`).
   - Decide whether a Laravel backend shares the storefront's `web` source (so browser and server events land in one stream; recommended for dedup and profile merging) or gets its own source type.
3. **License:** MIT (open source) or `UNLICENSED` like `@fanfinity/sfere-node`.
4. **Host value:** confirm the per-tenant ingestion origin that merchants put in `SFERE_HOST`. Backend config currently points to `https://console.fanfinity.io`.

---

## 16. Definition of done

- The package installs with `composer require fanfinity/sfere-laravel`.
- It is configured with two env vars, `SFERE_HOST` and a **server** `SFERE_WRITE_KEY`.
- It is used with one line, `Sfere::track(Event::PURCHASE, [...])`.
- Its events match WIRE-CONTRACT.md: same envelope, same `context` fields as `@fanfinity/sfere-node` (end-user IP, User-Agent and click IDs included), same `sfere_dedup_id` rule.
- It delivers reliably, with partial failures detected and reported, and it never crashes the host app.
- Tests, static analysis and docs all pass in CI.
