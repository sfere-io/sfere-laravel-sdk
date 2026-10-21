# Implementation notes

Internal notes for maintainers: what was verified, where the SDK deviates from the wire contract, and what is still open. This file is not shipped (it is excluded in `.gitattributes`), so it names the underlying platform.

**Source of truth:** the `fanfinity/sfere-sdk` repo:
- `docs/WIRE-CONTRACT.md`
- `docs/EVENTS.md`
- `docs/HTTP-API.md`
- `packages/node/src/context.ts`
- `packages/shared/src/index.ts`

## Facts verified in the ingest code

Sfere runs a fork of Jitsu (`fanfinity/jitsu`, line `sfere/2.14.0`). Ingest is the Go service in `bulker/ingest/`. These facts were checked in code on 2026-10-19; the public jitsu.com docs were deliberately **not** used.

### Endpoints
In `router.go:169-197`:
- `POST /api/s/s2s/:tp` (`:185`): single events, used by `sync` mode.
- `POST /v1/batch` (`:176`): used by `batch` and `queue` mode.
- `/api/s/:tp`, `/v1/batch/b` and `/api/s/s2s/batch` also exist; the SDK does not use them.
- `:tp` accepts `page`, `identify`, `track`, `group`, `alias` and `screen`, and the URL type overrides the body's `type`. A `track` needs `event`, at most 128 characters.

### Auth
`getDataLocator`, `router.go:406-439`, in order:
1. `Authorization: Bearer`.
2. `Authorization: Basic base64(key)` or `base64(key:)`. The trailing `:` is trimmed.
3. `X-Write-Key` (`:430`).
4. `?writekey=` (`:432`).
5. `writeKey` in the body, batch only.

The SDK sends Basic auth, and `writeKey` in the body on batch requests, as every Sfere SDK does. It does not use query-string auth, which leaks into access logs.

### Key type, not path, decides the ingest type
- Private keys bind as `s2s` and public keys as `browser` (`repository.go`).
- With a browser key, `router.go:391-404` overwrites `context.ip` with the request IP, fills `userAgent` and `locale` from headers, and runs `FilterEvent`.
- With a server key, `context.ip` and `context.userAgent` are kept exactly as sent and **never filled in**.
- The caller's address always goes to the top-level `requestIp`.

This is why the SDK must forward the end user's IP and User-Agent itself, and why a browser key silently corrupts the IP.

### Encoding
- `Content-Encoding: gzip` is decoded only by `BatchHandler` (`router_batch_handler.go:194`).
- `IngestHandler` reads the raw body, so a gzipped single event fails JSON parsing with a 400.
- The SDK therefore gzips only `/v1/batch`, and only when `http.compression` is on.

### Size
- There is no request-body limit.
- Each event, after ingest wraps it, is checked against `MAX_INGEST_PAYLOAD_SIZE`, which defaults to 1,000,000 (`config.go:65`, `router.go:944`).
- The SDK caps each event at 900 KB and each batch at 100 events and 500 KB (WIRE-CONTRACT §5).

### Batch responses
`router_batch_handler.go:287-351`:
- Always 200 with `{ok, receivedEvents, okEvents, errors?}`.
- `ok` is `processedEvents == okEvents`.
- Per-event failures are listed in `errors[]` as `"Message ID: <id>: <reason>"` (`:316`, `:325`).
- The SDK reads `okEvents < receivedEvents` and `errors[]` as a partial failure and does not retry those events.

### Single-event responses on the s2s path
- 400 for parse or event errors.
- 401 `stream not found` for an unknown key.
- 402 when throttled.
- 500 for producer errors.
- **200 with `{"error":"no destinations found for stream: …"}`.**

The SDK treats any 2xx without `ok === true` as a failure, as the Salla and Zid relays in the console do (`webapps/console/lib/server/sfere/salla/webhook.ts:341-348`).

### Field stamping
`patchEvent`, `router.go:338-403`:
- `receivedAt` is always overwritten.
- `timestamp` and `messageId` are set only if absent.
- `sentAt` is honoured on batch only, to correct clock skew of 5 minutes or more (`router_batch_handler.go:22-68`).
- The batch-envelope `context` is merged shallowly, with the event's keys winning. The SDK never sends an envelope `context`; each event carries its own.

### Dedup
- Ingest does no messageId dedup.
- The warehouse dedups on `message_id`.
- Every CAPI destination sends `messageId` as the platform's `event_id` (`docs/sfere/field-mapping-reference.md:103-122`).
- Hence caller-settable messageIds, and messageIds fixed at creation, so queue retries are safe.

## Deviations from the wire contract

| Item | Contract | This SDK | Why |
|---|---|---|---|
| `context.library.base` | `"<underlying library>@<version>"` | `"laravel/framework@<app()->version()>"` | Native implementation on Laravel's `Http` client; no wrapped analytics library (Segment's `analytics-php` bypasses `Http::fake()` and cannot reshape the wire format) |
| `context.library.name` | `@fanfinity/sfere-<sdk>`; §7 does not list a PHP SDK | `@fanfinity/sfere-laravel` | **Pending:** add to WIRE-CONTRACT §7 and the §4 "Server" column |
| `anonymousId` from the `ajs_anonymous_id` cookie | Not in the contract; `@fanfinity/sfere-node` does not do it | On by default (`context.anonymous_id_cookie`), documented as experimental | Lets profiles merge server events with browser sessions. **Pending:** add to WIRE-CONTRACT §4 and port to `sfere-node` |
| `screen` | Listed type | Not exposed | Mobile only |
| Event names | GA4 names (EVENTS.md) | GA4 constants in `Sfere\Ecommerce\Event`, not enforced | Matches EVENTS.md ("any other name is accepted and forwarded as-is"). The fork's adapters also accept Segment spec names |

## Distribution

Public on packagist.org as `sfere-io/sfere-laravel` from 0.1.0: `composer require sfere-io/sfere-laravel`, no repository or token. Release flow and the one-time Packagist submission: `docs/ci-cd.md` ("Releasing", "Publishing on packagist.org").

## Open owner actions (spec §15)

1. **Contract update** in `fanfinity/sfere-sdk`:
   - Add `@fanfinity/sfere-laravel` to WIRE-CONTRACT §7 and to the §4 Server column.
   - Add the anonymousId-from-cookie rule, and port it to `@fanfinity/sfere-node`.
2. **Server keys for Laravel merchants:**
   - Today they are minted with `POST /v1/accounts/{account_id}/sources/{source_id}/write-keys` and `{ "kind": "private" }`.
   - This is allowed only on `web` sources (`backend/app/services/sources.py:149`).
   - Decide whether the Laravel backend shares the storefront's `web` source (recommended, for dedup and profile merging) or gets its own source type.
3. **License:**
   - `LICENSE.md` is MIT (copyright 2026 Fanfinity), as the original spec asked.
   - `@fanfinity/sfere-node` is `UNLICENSED`.
   - **Pending owner confirmation** before publishing to Packagist.
4. **Host value (decided 2026-10-21):** the production ingestion origin is `https://events.sfere.io`, shown as `SFERE_HOST` in the README setup example.
5. **Credential hygiene (unrelated to this package):** `backend/push_events_demo57.py:7-17` hardcodes a live API key, a write key and ClickHouse credentials. Rotate them and remove them from the file.

## How the Phase 2 live test is done

### Local Sfere stack, 2026-10-19 (done)

Run against the local dev stack of the Sfere fork (`jitsu/dev-env`: ingest `jitsucom/ingest:2.14.0` on
`:3049`, Kafka, rotor from source, console). Setup:

- A stream `laravel-e2e-stream` with a **private** key `laraveles2s:…` and a public key, linked to a
  webhook destination `laravel-e2e-webhook` → `http://localhost:9998/hook` (a local sink), created through the
  console config API as `dev-env/jitsu-dev.sh smoke` does.
- A fresh `laravel/laravel` 13.37 app (Guzzle 8) requiring this package through a symlinked path
  repository. Auto-discovery and `php artisan sfere:install` worked.

Results:

| Check | Result |
|---|---|
| `sfere:test` with a wrong secret | `invalid write key (stream not found) … (HTTP 401, 1 attempt(s))`, exit 1, no retry |
| `sfere:test` with the server key | `Event delivered (… HTTP 200)`, the event reached the webhook |
| Batch mode, `GET /checkout/thanks` with `X-Forwarded-For: 203.0.113.7, 10.0.0.1`, an iPhone UA, `Accept-Language: ar-SA`, cookies `_fbp`, `_gcl_aw`, `_clck`, `ajs_anonymous_id=%22anon-7f3c%22`, `?utm_*&fbclid=` | `identify` + `purchase` delivered in one `/v1/batch` at terminate. `context.ip=203.0.113.7` (`requestIp` was the server's), `userAgent`, `locale=ar-SA`, `page`, `campaign`, `clientIds {fbp, gclid, clarity_uid, fbc=fb.1.<ms>.IwAR123}`, `anonymousId=anon-7f3c`, `messageId = sfere_dedup_id = purchase-order_98765`, library `@fanfinity/sfere-laravel 1.0.0 / laravel/framework@13.37.0` |
| `TrackPageViews` on an HTML GET | `page` event with url/path/search, `clientIds.gclid` from the landing URL |
| Queue mode (database queue) | one `SendEncryptedEvents` job per flush; the payload does not contain the IP in clear text; `queue:work` delivered both events with the context captured in the request |
| Ingest unreachable (`SFERE_HOST=http://localhost:3999`) | the call returned its messageId, three attempts with backoff (~1.5 s, after the response), one `local.ERROR` line with count and messageIds (no key, no payload), exit 0 |

Found while testing:

- The package required `guzzlehttp/guzzle ^7.8`, but new Laravel 13 apps ship Guzzle 8: the install failed.
  Fixed by dropping the direct requirement (`illuminate/http` already allows `^7.8.2 || ^8.0`). The suite
  passes on Guzzle 7 and 8.
- Not an SDK problem: the local rotor's Kafka consumer had died (`max.poll.interval.ms exceeded`, then
  `Local: Fatal error`) while its health check still answered 200, so nothing left Kafka. Restarting rotor
  delivered the backlog. Check `rpk group describe rotor` (MEMBERS must be 1) when local events vanish.

### Staging (still to do)

- Staging host: `TODO`
- Stream / source: `TODO` (a `web` source with a **private** key)
- Command: `SFERE_HOST=… SFERE_WRITE_KEY=… php artisan sfere:test`
- Result: `TODO`
- Date / by: `TODO`
