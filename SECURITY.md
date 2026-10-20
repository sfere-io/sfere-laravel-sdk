# Security Policy

## Reporting a Vulnerability

This repository is private. Please do **not** open a public issue or discuss the
vulnerability in a pull request.

Report vulnerabilities privately to the repository administrators
(@mabumusa1, @KaramYaaqba) — never in an issue or pull request. Contact the
maintainers directly (GitHub DM or email), or open a private security advisory
from the repository's Security tab if you have write access.

Include:

- A description of the issue and its impact
- Steps to reproduce or a proof of concept
- Affected files, commits, or versions if known

We will acknowledge the report within a few working days and keep you updated on
the fix and disclosure timeline.

## Scope

- The package (`src/`, `config/`), its Composer metadata and the CI/release
  workflows (`.github/workflows/**`, `.github/scripts/**`, `.githooks/**`)
- The released versions (`v*` tags, installed by Composer)
- Dependency vulnerabilities in `composer.json`'s constraints (tracked via
  Dependabot alerts and security updates, and `composer audit` in CI)

Out of scope: the test suite (`tests/`), which never ships (export-ignore).

## What the SDK promises

- It talks only to the host the app configures (`SFERE_HOST`): no telemetry, no
  remote settings, no third-party hosts.
- It never hashes or reformats email or phone values.
- The server write key is a secret: it comes from the app's environment, never
  from the package, and queued payloads are encrypted by default (`queue.encrypt`).
- It never throws into the host app's request by default (failures are logged and
  fire `DeliveryFailed`).

## Preferred Practices

- Never commit secrets. Write keys for staging live in the git-ignored `.env`;
  tests use fakes.
- All changes to `main` go through a reviewed pull request; direct pushes,
  force-pushes, and deletions are blocked by repository rulesets.
- Release tags (`v*`) can be created by repository admins only; a released
  version is immutable.
