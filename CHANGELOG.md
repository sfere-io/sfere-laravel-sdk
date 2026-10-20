# Changelog

All notable changes to `fanfinity/sfere-laravel` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The `release` workflow uses the section for the tagged version as the GitHub release notes, so write entries for app developers. Every change to the package adds an entry under `## [Unreleased]` (CI checks it; see `docs/ci-cd.md`).

## [Unreleased]

## [1.0.0] - 2026-10-19

### Added

- `track`, `identify`, `page`, `group` and `alias`. Each returns the event's messageId.
- `Sfere::event()` fluent builder.
- Scoped clients `forUser()`, `anonymous()` and `withContext()`, with `resolveUserIdUsing()` and `resolveTraitsUsing()` resolvers.
- Request context, captured at call time: the customer's IP, user agent, locale, page, campaign (`utm_*`, `gad_*`) and ad click IDs from cookies and landing URLs. It reads the raw `Cookie` header, so it works with `EncryptCookies`.
- `Sfere::requestContext()`, for storing the context and reusing it on webhook and cron events.
- `properties.sfere_dedup_id` on every `track`. Callers can set their own `messageId` for browser and server deduplication.
- `Sfere\Ecommerce\Event` constants for the GA4 e-commerce event names.
- Delivery modes `sync`, `batch` (default) and `queue`. Queued payloads are encrypted by default (`queue.encrypt`).
  - Batches respect the 100-event and 500 KB limits.
  - Lifecycle flushing at request end, after jobs and commands, on Octane `RequestTerminated`, and on a time limit.
- HTTP transport built on Laravel's `Http` client.
  - Basic auth.
  - Retries with exponential backoff and jitter on network errors, 429 and 5xx, honouring `Retry-After`.
  - Partial-batch and "200 with error" detection.
  - Optional gzip for batch requests.
- `EventsSent` and `DeliveryFailed` Laravel events.
- `Sfere::fake()` with `assertTracked`, `assertNotTracked`, `assertIdentified`, `assertNothingSent` and `assertSentCount`.
- `sfere:install` and `sfere:test` Artisan commands.
- `TrackPageViews` middleware.
- Validation: messageId format, event name length, placeholder user ids, a userId or anonymousId required, and a 900 KB event size limit.
- No-op mode with a single warning when the SDK is disabled or misconfigured.

[Unreleased]: https://github.com/sfere-io/sfere-laravel-sdk/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sfere-io/sfere-laravel-sdk/releases/tag/v1.0.0
