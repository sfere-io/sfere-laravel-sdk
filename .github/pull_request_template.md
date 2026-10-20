## What

<!-- What does this PR change? -->

## Why

<!-- Link the issue or context. Why is this the right change? -->

## How was this tested

All commands run from the repository root with PHP 8.2+ (`composer install` first).

- [ ] `composer ci` (Pint, PHPStan, PHPUnit), or explain why not applicable
- [ ] `php artisan sfere:test` against staging (if delivery or the payload changed)

## Checklist

- [ ] What goes on the wire still follows sfere-sdk `docs/WIRE-CONTRACT.md` and `docs/EVENTS.md`
- [ ] `CHANGELOG.md` has an entry under `## [Unreleased]` (package changes; label `skip-changelog` otherwise)
- [ ] `Library::VERSION` in `src/Support/Library.php` is not an already-tagged version
- [ ] A breaking public API change moves the major version (CI's "API contract" job lists the breaks)
- [ ] No vendor name for the ingestion platform anywhere (say "Sfere ingestion")
- [ ] No secrets or real write keys in the diff (`.env` is git-ignored; tests use fakes)
- [ ] CI or release changes (`.github/**`, `.githooks/**`, `sonar-project.properties`) come with a `docs/ci-cd.md` update
