# Sfere for Laravel

Send analytics and conversion events from your Laravel backend to **Sfere ingestion**.

The SDK forwards your customer's IP, user agent and ad click IDs with every server event. Ad-platform conversion APIs (Meta, TikTok, Google Ads, Snap, …) match on exactly these values. Sfere ingestion does not fill them in for server-sent events: the request comes from your server, not from your customer.

```php
use Sfere\Facades\Sfere;
use Sfere\Ecommerce\Event;

Sfere::forUser(auth()->user())->track(Event::PURCHASE, [
    'transaction_id' => 'order_98765',
    'currency'       => 'SAR',
    'value'          => 256.96,
    'items'          => [['item_id' => 'sku_12345', 'item_name' => 'Away Jersey', 'price' => 89.99, 'quantity' => 2]],
]);
```

- Never throws into your request by default. Failures are logged, and a `DeliveryFailed` event is fired.
- Batches events in memory and sends them after the response, or hands them to a queue.
- Ships a `Sfere::fake()` for your tests.

**Requirements:** PHP 8.2+, Laravel 12 or 13 (Laravel 13 needs PHP 8.3+).

---

## Contents

- [Installation](#installation)
- [Getting a server key](#getting-a-server-key)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [API reference](#api-reference)
- [E-commerce events](#e-commerce-events)
- [Request context](#request-context)
- [Events outside a customer request (webhooks, cron)](#events-outside-a-customer-request-webhooks-cron)
- [Browser and server deduplication](#browser-and-server-deduplication)
- [Identity](#identity)
- [Delivery modes](#delivery-modes)
- [Laravel events](#laravel-events)
- [Testing your app](#testing-your-app)
- [Page-view middleware](#page-view-middleware)
- [Privacy](#privacy)
- [Troubleshooting](#troubleshooting)

---

## Installation

```bash
composer require fanfinity/sfere-laravel
php artisan sfere:install
```

The service provider and the `Sfere` facade are auto-discovered. `sfere:install` publishes `config/sfere.php` and prints the environment variables to add:

```dotenv
SFERE_HOST=https://ingest.example.com
SFERE_WRITE_KEY=keyId:secret
```

Check the credentials with a real test event:

```bash
php artisan sfere:test
```

## Getting a server key

Sfere gives you two kinds of write key, both in the form `keyId:secret`:

| Key | Where it goes |
|---|---|
| **Browser key** | Web pages and apps. It is public by design. |
| **Server key** | Your backend only. It is a secret. |

**This package needs a server key.** Create one in the Sfere dashboard on your source's write keys. The secret is shown only once; store it as `SFERE_WRITE_KEY` in the form `keyId:secret`.

> ⚠️ A browser key is *accepted* by ingestion, but then every event reports **your server's IP** instead of your customer's. Conversions stop matching on ad platforms. If your events all show the same IP, see [Troubleshooting](#troubleshooting).

`SFERE_HOST` is the ingestion origin Sfere gives you with your keys. Use the scheme and host only, with no path: `https://ingest.example.com`, not `https://ingest.example.com/v1/batch`.

## Configuration

All keys in `config/sfere.php`:

| Key | Env | Default | Notes |
|---|---|---|---|
| `enabled` | `SFERE_ENABLED` | `true` | `false` turns every call into a no-op |
| `host` | `SFERE_HOST` | — | Ingestion origin, no path |
| `write_key` | `SFERE_WRITE_KEY` | — | **Server** key, `keyId:secret` |
| `delivery` | `SFERE_DELIVERY` | `batch` | `sync`, `batch` or `queue`. See [Delivery modes](#delivery-modes) |
| `batch.max_size` | | `100` | Events per request (capped at 100) |
| `batch.max_bytes` | | `500000` | Bytes per request (capped at 500 KB) |
| `batch.max_wait_seconds` | | `10` | Flush when the oldest buffered event is this old (long-running processes) |
| `queue.connection` | `SFERE_QUEUE_CONNECTION` | default connection | Queue mode only |
| `queue.name` | `SFERE_QUEUE` | `default` | Queue mode only |
| `queue.tries` | | `5` | Attempts per `SendEvents` job |
| `queue.backoff` | | `[10, 30, 60, 120]` | Seconds between job attempts |
| `queue.encrypt` | `SFERE_QUEUE_ENCRYPT` | `true` | Encrypt queued payloads with the app key (`ShouldBeEncrypted`); they carry PII |
| `http.timeout` | | `3` | Seconds per request |
| `http.connect_timeout` | | `2` | Seconds to connect |
| `http.retries` | | `3` | Attempts per request on network errors, 429 and 5xx |
| `http.max_retry_after` | | `10` | Cap, in seconds, on a server's `Retry-After` |
| `http.compression` | | `false` | Gzip batch request bodies |
| `context.include_request` | | `true` | Add the customer's IP, user agent, locale, page, campaign and click IDs |
| `context.trust_proxy` | | `true` | Read the client IP from the left-most `X-Forwarded-For`. `false` uses `$request->ip()`, which follows your app's TrustProxies setup |
| `context.anonymous_id_cookie` | | `ajs_anonymous_id` | Browser anonymous id cookie, read when an event has none. `null` disables it. Experimental |
| `context.static` | | `[]` | Merged into every event's `context` |
| `throw_exceptions` | `SFERE_THROW` | `false` | Throw on invalid events and delivery failures instead of logging |
| `log_channel` | `SFERE_LOG_CHANNEL` | default channel | Where warnings and failures are logged |

If `enabled` is false, or `host` or `write_key` is missing or invalid, the SDK becomes a no-op. It then logs **one** warning per process that says why.

## Quick start

```php
use Sfere\Facades\Sfere;
use Sfere\Ecommerce\Event;

// Who the user is
Sfere::identify('10293', ['email' => 'fan@example.com', 'phone' => '+966500000000']);

// What they did
Sfere::track(Event::ADD_TO_CART, [
    'currency' => 'SAR',
    'value'    => 89.99,
    'items'    => [['item_id' => 'sku_12345', 'item_name' => 'Away Jersey', 'price' => 89.99, 'quantity' => 1]],
], userId: '10293');

// Or let the SDK take the id from the authenticated user
Sfere::forUser(auth()->user())->track(Event::SIGN_UP, ['method' => 'email']);
```

Inside an HTTP request, the customer's IP, user agent, page, campaign and click IDs are added automatically. In `batch` mode, the events are sent after the response.

## API reference

Every send method returns the event's **messageId** (a string). It returns one even when the SDK is disabled or faked, so your code never needs to branch on it.

### `track`

```php
Sfere::track(
    string $event,
    array $properties = [],
    string|int|null $userId = null,
    ?string $anonymousId = null,
    ?DateTimeInterface $timestamp = null,
    array $context = [],
    ?string $messageId = null,
    ?array $integrations = null,
): string
```

`properties.sfere_dedup_id` is set on every `track`. Your own value is kept; otherwise it is the event's `messageId`.

### `identify`

```php
Sfere::identify(
    string|int|null $userId = null,
    array $traits = [],
    ?string $anonymousId = null,
    ?DateTimeInterface $timestamp = null,
    array $context = [],
    ?string $messageId = null,
    ?array $integrations = null,
): string
```

### `page`

A server-side page view.

```php
Sfere::page(
    ?string $name = null,
    array $properties = [],
    string|int|null $userId = null,
    ?string $anonymousId = null,
    ?DateTimeInterface $timestamp = null,
    array $context = [],
    ?string $messageId = null,
    ?array $integrations = null,
): string
```

### `group`

```php
Sfere::group(
    string|int $groupId,
    array $traits = [],
    string|int|null $userId = null,
    ?string $anonymousId = null,
    ?DateTimeInterface $timestamp = null,
    array $context = [],
    ?string $messageId = null,
    ?array $integrations = null,
): string
```

### `alias`

Links a previous id, usually the anonymous id, to a user id.

```php
Sfere::alias(
    string|int $previousId,
    string|int|null $userId = null,
    ?DateTimeInterface $timestamp = null,
    array $context = [],
    ?string $messageId = null,
    ?array $integrations = null,
): string
```

### Scoped clients

These return a **new** client and never change the shared one, so they are safe under Octane.

```php
Sfere::forUser(?Authenticatable $user);   // userId (and resolver traits) on every event
Sfere::anonymous(?string $anonymousId);   // anonymousId on every event
Sfere::withContext(array $context);       // merged into every event's context

Sfere::forUser($user)->withContext(['campaign' => ['source' => 'newsletter']])->track(Event::LOGIN);
```

### Fluent builder

For advanced cases. `send()` returns the messageId.

```php
Sfere::event('track')
    ->name(Event::ADD_TO_CART)       // event name on track, page name on page
    ->userId('10293')
    ->anonymousId($anonymousId)
    ->messageId('cart-abc-1')
    ->timestamp(now())
    ->properties(['currency' => 'SAR', 'value' => 89.99, 'items' => [/* … */]])
    ->context(['campaign' => ['source' => 'newsletter']])
    ->integrations([/* … */])
    ->send();
```

The builder also has `traits()` (identify, group), `groupId()` (group) and `previousId()` (alias).

### Other methods

| Method | What it does |
|---|---|
| `Sfere::resolveUserIdUsing(?Closure $resolver)` | How `forUser()` gets the user id. Default: `getAuthIdentifier()`, cast to string |
| `Sfere::resolveTraitsUsing(?Closure $resolver)` | Traits `forUser()` adds as `context.traits`. No default |
| `Sfere::requestContext(?Request $request = null, ?string $pageUrl = null): array` | The customer context of a request. See [Request context](#request-context) |
| `Sfere::flush()` | Send (or enqueue) everything buffered now |

### Overrides

```php
Sfere::track('import_completed', [], userId: 'u1', timestamp: now()->subDay(), context: ['source' => 'csv']);
```

### Invalid input

The SDK checks these rules before it buffers an event:
- `track` needs an event name, at most 128 characters.
- Every event needs a `userId` or an `anonymousId`.
- A `messageId` you pass must match `[A-Za-z0-9._-]`, at most 64 characters.
- `userId` cannot be a placeholder value.
- An event cannot be larger than 900 KB.

An invalid event is logged and dropped. With `throw_exceptions` on, it throws `Sfere\Exceptions\InvalidEventException` instead.

## E-commerce events

Sfere uses the **GA4 e-commerce vocabulary** for event and property names. A single instrumentation then feeds analytics and every server-side conversion destination. `Sfere\Ecommerce\Event` has a constant for each name:

| Constant | Event | When |
|---|---|---|
| `VIEW_ITEM` | `view_item` | Product detail page viewed |
| `VIEW_ITEM_LIST` | `view_item_list` | Product listing viewed |
| `SELECT_ITEM` | `select_item` | Product clicked in a list |
| `ADD_TO_CART` | `add_to_cart` | Product added to cart |
| `REMOVE_FROM_CART` | `remove_from_cart` | Product removed from cart |
| `ADD_TO_WISHLIST` | `add_to_wishlist` | Product added to a wishlist |
| `VIEW_CART` | `view_cart` | Cart viewed |
| `BEGIN_CHECKOUT` | `begin_checkout` | Checkout started |
| `ADD_SHIPPING_INFO` | `add_shipping_info` | Shipping method chosen |
| `ADD_PAYMENT_INFO` | `add_payment_info` | Payment details entered |
| `PURCHASE` | `purchase` | Order completed |
| `REFUND` | `refund` | Order refunded |
| `SEARCH` | `search` | Site search submitted |
| `SIGN_UP` | `sign_up` | Account created |
| `LOGIN` | `login` | User logged in |
| `GENERATE_LEAD` | `generate_lead` | Lead form submitted |

Other names are accepted and forwarded unchanged.

**Property rules:**
- Properties are snake_case GA4 fields.
- `currency` is an ISO 4217 code (`SAR`, `USD`, `AED`). GA4 requires it whenever `value` is set.
- `value` is a number, not a string.
- Products go in `items[]`. Each item needs an `item_id` or an `item_name`.

```php
$messageId = Sfere::track(Event::PURCHASE, [
    'transaction_id' => 'order_98765',
    'currency'       => 'SAR',
    'value'          => 256.96,
    'tax'            => 16.99,
    'shipping'       => 10.0,
    'coupon'         => 'SUMMER20',
    'items'          => [
        ['item_id' => 'sku_12345', 'item_name' => 'Away Jersey', 'price' => 89.99, 'quantity' => 2, 'coupon' => 'SUMMER20'],
        ['item_id' => 'sku_67890', 'item_name' => 'Home Shorts', 'price' => 49.99, 'quantity' => 1],
    ],
], userId: '10293', messageId: 'purchase-order_98765');
```

**Item fields:**
- `item_id`, `item_name`, `price`, `quantity`.
- `item_brand`, `item_category` … `item_category5`, `item_variant`.
- `item_list_id`, `item_list_name`, `index`.
- `coupon`, `discount`, `affiliation`, `location_id`.

Extra keys are forwarded unchanged.

## Request context

Inside an HTTP request, every event gets this `context` from the customer's request:

| Field | From |
|---|---|
| `context.ip` | Left-most `X-Forwarded-For`, then `X-Real-IP`, then the socket address. With `trust_proxy` off: `$request->ip()` |
| `context.userAgent` | `User-Agent` |
| `context.locale` | First `Accept-Language` tag |
| `context.page` | `url`, `path`, `search` of the request URL, plus `referrer` from `Referer` |
| `context.campaign` | `utm_*` → `source`, `medium`, `name`, `term`, `content`, plus `gad_source` and `gad_campaignid`. Read from the page URL, else from the referrer |
| `context.clientIds` | Ad cookies (`_fbp`, `_fbc`, `_ttp`, `_gcl_aw`, `_scid`, `_clck`, …) and landing click IDs (`gclid`, `ttclid`, `msclkid`, …). A landing `fbclid` becomes `fbc` |

The context is captured **when you call `track()`**, not when the event is sent. Batched and queued events therefore keep it.

**`trust_proxy`.** Most deployments sit behind a load balancer, so it defaults to `true`. Set it to `false` if the app is reached directly; otherwise a client could choose the IP it is reported with.

**Cookies and `EncryptCookies`.** Laravel's `EncryptCookies` middleware turns every cookie it did not encrypt (`_fbp`, `_gcl_aw`, …) into `null` on `$request->cookie()`. The SDK reads the raw `Cookie` header instead, so you do **not** need to add the ad cookies to `EncryptCookies::$except`.

**API calls from a page.** When the request is an API call made by a page (for example the checkout page's AJAX call), pass that page's URL. Its query string then supplies the landing click IDs and UTM parameters:

```php
$context = Sfere::requestContext($request, pageUrl: $request->input('page_url'));
Sfere::track(Event::BEGIN_CHECKOUT, $props, userId: $id, context: $context);
```

## Events outside a customer request (webhooks, cron)

A payment webhook, a cron job or a refund issued by staff has no customer request, so there is no IP or click ID to read. Save the customer's context while you have it, and pass it back later:

```php
// At checkout (customer request): store the context with the order
$order->sfere_context = Sfere::requestContext();   // e.g. a JSON column
$order->save();

// Later, in the payment provider's webhook
Sfere::track(Event::PURCHASE, $props, userId: $order->user_id, context: $order->sfere_context ?? []);
```

You can also send without a context. The event is still recorded, but it matches less well on ad platforms.

## Browser and server deduplication

If a browser pixel and Sfere both report the same conversion, the ad platform counts it once only when both carry the **same event ID and the same event name**. Sfere's server-side destinations use the event's `messageId` as that ID.

`track()` returns the messageId. Pass a deterministic one, or use the returned one, and render it into the page for the pixel:

```php
// Controller
$eventId = Sfere::forUser($user)->track(Event::PURCHASE, $props, messageId: "purchase-{$order->id}");

return view('checkout.thanks', ['sfereEventId' => $eventId]);
```

```blade
<script>
  fbq('track', 'Purchase', {value: {{ $order->total }}, currency: 'SAR'}, {eventID: @json($sfereEventId)});
  ttq.track('CompletePayment', {}, {event_id: @json($sfereEventId)});
</script>
```

Other platforms take the same value:
- Snap: `client_dedup_id`.
- LinkedIn: `event_id`.
- Microsoft UET: `event_id`.
- X: `conversion_id`.
- Google Ads: `transaction_id`.

**Notes:**
- **Names must match too.** The pixel's event name must be the one the destination sends for your event. For example, `purchase` becomes Meta `Purchase`.
- **Retries are safe.** A messageId is fixed when the event is created, so retries never create duplicates.
- **`properties.sfere_dedup_id`** is set on every `track`, but the server-side destinations do not read it. Do not use it to pass an order ID to the pixel instead of the messageId.

## Identity

**User id rules.** The `userId` must be your **stable, opaque, non-PII internal id**, sent as a string. Integer ids are cast for you; `"10293"` is fine. Do not use an email or a phone number. The placeholder values `0`, `-1`, `null`, `undefined`, `test`, `na` and `n/a` are rejected, as are ids longer than 512 characters.

**The authenticated user:**

```php
Sfere::forUser(auth()->user())->track(Event::LOGIN, ['method' => 'password']);
Sfere::forUser(null)->track(...);   // no user: falls back to the anonymous id
```

**Resolvers.** Set these once, for example in `AppServiceProvider::boot()`:

```php
use Sfere\Facades\Sfere;

// Use a UUID column instead of the primary key
Sfere::resolveUserIdUsing(fn ($user) => $user->uuid);

// Add email and phone to context.traits of every forUser() event (improves ad matching)
Sfere::resolveTraitsUsing(fn ($user) => ['email' => $user->email, 'phone' => $user->phone]);
```

There is no default traits resolver: sending PII is your decision. On `identify`, resolver traits are merged into `traits`, and traits you pass explicitly win.

**Anonymous visitors:**

```php
Sfere::anonymous($anonymousId)->track(Event::BEGIN_CHECKOUT, $props);
```

**Anonymous id from the browser (experimental).** When an event has no `anonymousId`, the SDK reads the browser SDK's anonymous id cookie (`context.anonymous_id_cookie`, default `ajs_anonymous_id`) from the current request. That links server events to the visitor's browser session for profile merging. Set the option to `null` to turn this off.

## Delivery modes

Set the mode with `SFERE_DELIVERY`.

| Mode | When events leave | Use for |
|---|---|---|
| `sync` | Immediately, one request per event, inside your call | The console and debugging. **Retries block your code**, up to a few seconds when ingestion is slow or down |
| `batch` (default) | In batches of up to 100 events and 500 KB, after the response | Web apps |
| `queue` | A `Sfere\Jobs\SendEvents` job per batch, on your queue | When you want the durability and retries of your queue |

**When `batch` mode flushes:**
- At the end of each request (`terminating`). Under PHP-FPM this runs after the response is sent.
- After each queued job (`JobProcessed`, `JobFailed`).
- After each Artisan command (`CommandFinished`).
- Under Octane, on `RequestTerminated`. The buffer is flushed and reset, so nothing leaks between requests.
- When the buffer reaches `batch.max_size`.
- When the oldest buffered event is older than `batch.max_wait_seconds` (long-running daemons).

Call `Sfere::flush()` to send everything immediately.

**`queue` mode** uses the same buffering. Each flush dispatches a job on `queue.connection` / `queue.name`. The job retries up to `queue.tries` times with `queue.backoff`. Payloads are encrypted with your `APP_KEY` (`SendEncryptedEvents`) unless `queue.encrypt` is false, because they carry traits, IPs and click IDs.

**Retries:**
- Network errors, `429` and `5xx` are retried with exponential backoff and jitter, honouring `Retry-After`.
- Other `4xx` responses (bad request, invalid key, throttled) are never retried.

## Laravel events

| Event | Fired when | Properties |
|---|---|---|
| `Sfere\Events\EventsSent` | Ingestion accepted events | `messageIds`, `okEvents` |
| `Sfere\Events\DeliveryFailed` | Events were not accepted after every retry, including partially rejected batches | `messageIds`, `status`, `reason`, `retryable` |

```php
use Illuminate\Support\Facades\Event;
use Sfere\Events\DeliveryFailed;

Event::listen(function (DeliveryFailed $e) {
    report(new RuntimeException("Sfere: {$e->reason} for ".count($e->messageIds).' events'));
});
```

Failures are also logged to `log_channel`, with the event count, messageIds, status and reason. The write key and event payloads are never logged.

## Testing your app

`Sfere::fake()` records events in memory and makes no HTTP calls. The recorded events are the **exact** arrays that would be sent, including `context`.

```php
use Sfere\Facades\Sfere;
use Sfere\Ecommerce\Event;

public function test_checkout_tracks_a_purchase(): void
{
    Sfere::fake();

    $this->actingAs($user)->post('/checkout', $payload);

    Sfere::assertTracked(Event::PURCHASE);
    Sfere::assertTracked(Event::PURCHASE, fn (array $event) => $event['properties']['value'] === 256.96);
    Sfere::assertIdentified('10293');
    Sfere::assertNotTracked(Event::REFUND);
    Sfere::assertSentCount(2);
}

public function test_nothing_is_tracked_for_guests(): void
{
    Sfere::fake();

    $this->get('/');

    Sfere::assertNothingSent();
}
```

`track()` still returns a messageId under the fake.

## Page-view middleware

`Sfere\Http\Middleware\TrackPageViews` sends a `page` event for every `GET` request that returns a successful HTML response. It skips `/horizon*`, `/telescope*`, `/livewire*` and `/_debugbar*`.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [\Sfere\Http\Middleware\TrackPageViews::class]);
})
```

To skip more paths, extend the class and override `$except`.

> ⚠️ If the Sfere **browser** SDK is also installed on your pages, it already sends page views. Adding this middleware then **counts every page view twice**. Use one or the other.

## Privacy

- **Request context.** `context.include_request` (default `true`) adds the customer's IP, user agent, locale, page, campaign and ad click IDs to server events. You can turn it off, but then server conversions reach ad destinations without their main match signals. The SDK logs a one-time warning when it is off.
- **No hashing.** Traits (email, phone) are sent as plain values. Each destination hashes and normalises them the way its platform requires. Do not hash them yourself.
- **PII is opt-in.** Traits are sent only when you pass them, or set a traits resolver.
- **Consent.** If your browser consent tool records consent, forward it in `context.consent.categoryPreferences`, e.g. `['advertising' => false, 'analytics' => true]`. The server cannot read it on its own.
- **The SDK talks only to `SFERE_HOST`.** No telemetry, no remote settings, no third-party hosts.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Nothing is sent at all | The SDK is a no-op. Look for the one-time warning `Sfere: events are not sent: …` in your log: it names the missing or invalid setting. Run `php artisan sfere:test` |
| `invalid write key` / 401 | The key is wrong, revoked, or not in `keyId:secret` form. Create a new server key |
| `no destinations configured for this stream` | Ingestion accepted the request, but the source has no destination connected, so the event went nowhere. Connect a destination in Sfere |
| Every event shows your **server's** IP | You are using a **browser** key. Switch `SFERE_WRITE_KEY` to a server key |
| Events have no click IDs (`context.clientIds`) | The event was sent outside the customer's request (pass a stored context), or a proxy or CDN strips cookies. On a landing page, pixel cookies may not exist yet on the very first request |
| The IP is your load balancer's | Keep `context.trust_proxy` on, and make sure the proxy sets `X-Forwarded-For` |
| The IP is wrong or spoofable | The app is reached directly: set `context.trust_proxy` to `false` and configure Laravel's TrustProxies |
| Events arrive late | In `batch` mode, events leave at the end of the request, job or command. Long-running processes flush every `batch.max_wait_seconds` |
| Some events are rejected in a batch | Listen for `DeliveryFailed`. Its `reason` comes from ingestion, and those events are not retried |

## License

MIT. See [LICENSE.md](LICENSE.md).
