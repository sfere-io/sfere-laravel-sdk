# Contributing to sfere-io/sfere-laravel-sdk

## Ground rules

- Everything lands on `main` through a pull request. Direct pushes, force-pushes,
  and branch deletion are blocked by repository rulesets — including for admins.
- What goes on the wire follows sfere-sdk `docs/WIRE-CONTRACT.md` and
  `docs/EVENTS.md`; `SFERE_SDK_SPEC.md` and `NOTES.md` record how this package
  meets them. The SDK never hashes emails or phone numbers and talks only to
  `SFERE_HOST`.
- Never name the ingestion vendor in code or in anything that ships: say "Sfere
  ingestion". Only the internal build notes (`NOTES.md`, `SFERE_SDK_SPEC*.md`,
  export-ignored) may.
- Secrets never enter the repository. Staging keys go in the git-ignored `.env`;
  tests use fakes.

## Local setup

PHP 8.2+ and Composer 2. Then, once per clone:

```sh
composer install
git config core.hooksPath .githooks
```

The pre-commit hook formats the staged PHP with Pint and refuses a commit that
names the ingestion vendor. `git commit --no-verify` is the escape hatch; CI is the
real gate. `composer ci` runs Pint, PHPStan and PHPUnit the way CI does.

## Pull request policy

Enforced by the `protect-main` ruleset on `main`:

| Rule | Setting |
|---|---|
| Pull request required | Yes |
| Approving reviews | 1 |
| Code owner review | Required for paths listed in `.github/CODEOWNERS` |
| Stale approvals | Dismissed when new commits are pushed |
| Last push approval | The most recent push must be approved by someone else |
| Review threads | All resolved before merge |
| Merge method | Squash only (linear history required) |
| Required checks | `PHP style`, `Static analysis`, `API contract`, `Tests & coverage`, `Compatibility`, `SonarQube`, `Build` |
| Branch up to date | Yes (strict checks) |
| Bypass | None — the rules apply to admins too |

Sensitive paths (`.github/`, `.githooks/`, `composer.json`, the tool configs,
`src/Support/Library.php`, `config/`, the policy docs) require approval from an SDK
maintainer (@mabumusa1 or @KaramYaaqba).

What the checks gate, and how to run them locally, is in [docs/ci-cd.md](docs/ci-cd.md).

## Review expectations

- Review the diff, not the description. If you didn't read it, don't approve it.
- A change to what the SDK sends is done when it matches the wire contract and the
  other Sfere SDKs send the same shape.
- A breaking change to the public PHP API shows up in the "API contract" job
  summary. It needs a new major version; read it as the contract it is.
- Keep PRs small and focused; large PRs get rubber-stamped.

## Versions, CHANGELOG and releases

- `Library::VERSION` in `src/Support/Library.php` is the package version, and every
  event reports it in `context.library.version`. A version that has a
  `v<version>` tag is released and immutable: bump `Library::VERSION` before
  changing the package again. CI fails a PR that changes the package under a
  tagged version.
- Every package change adds an entry under `## [Unreleased]` in `CHANGELOG.md`
  (label the PR `skip-changelog` for changes that don't affect what ships).
- Releasing: see [docs/ci-cd.md](docs/ci-cd.md#releasing). In short: set
  `Library::VERSION`, rename `[Unreleased]` to the version with the date, merge,
  then an admin tags `v<version>` on `main`. The `release` workflow reruns the
  gate, installs the tag as an app would and creates the GitHub release.

## Merge flow

1. Open the PR against `main` (the template will guide you).
2. Wait for the required checks to pass.
3. Get one approval (plus a maintainer for sensitive paths).
4. Squash-merge. The branch is deleted automatically.

## Hotfix / emergency bypass

If `main` is broken and the rules block an urgent fix:

1. One of the maintainers edits or deactivates the `protect-main` ruleset.
2. The fix lands (still prefer a PR).
3. The ruleset is restored immediately afterwards.
4. The bypass is recorded in the PR or commit message with the reason
   (`hotfix: <what> — ruleset bypass approved by <who>`), and reviewed
   post-merge within one working day.

Silent bypasses are not acceptable: a bypass without a recorded reason is
treated as an incident.

## Updating this policy

Changes to `.github/CODEOWNERS`, this file, or the rulesets themselves are
sensitive-path changes: they need a maintainer's approval and should be made in
a PR (except in the emergency flow above).
