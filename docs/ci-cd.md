# CI/CD for the Sfere Laravel SDK

How the code is protected on the way to `main`, and how a version reaches app
developers. The model is the one `sfere-io/apis` and `sfere-io/sfere-android-sdk`
use: every pull request passes the same named checks, `main` is protected by
rulesets with no bypass, Dependabot keeps dependencies and actions current, and a
release is a tag that only an admin can create.

## Workflows

All actions are **pinned by commit SHA** (the `# vX.Y.Z` comment says which
release). Keep it that way when editing a workflow; Dependabot bumps the pins.
Every workflow declares `permissions: contents: read` at the top and widens it only
on the job that needs more.

### `ci.yml` — the quality gate

| Item | Value |
|---|---|
| Triggers | `pull_request` (opened, synchronize, reopened); `push` to `main`; `workflow_call` (from `release.yml`) |
| Runner | `ubuntu-latest`, PHP from `shivammathur/setup-php` (8.4 unless the job says otherwise), Composer 2; the Composer cache is keyed on `composer.json` (a library commits no lock file) |
| Concurrency | one run per ref; a newer push to a PR cancels the older run |

| Job (check name) | What it runs | Fails when |
|---|---|---|
| **PHP style** | `vendor/bin/pint --test` (`pint.json`, Laravel preset) | any file is not formatted. Fix: `composer format`, or let the pre-commit hook do it |
| **Static analysis** | `composer validate --strict`, `composer audit`, PHPStan with Larastan at level 8 (`phpstan.neon`) | `composer.json` is invalid, a resolved package has a security advisory, or PHPStan reports anything |
| **API contract** | vendor-name grep over every tracked file; on PRs, Roave's [BackwardCompatibilityCheck](https://github.com/Roave/BackwardCompatibilityCheck) against the base branch, and the version and CHANGELOG rules | the ingestion vendor is named anywhere except the internal, export-ignored `NOTES.md` and `SFERE_SDK_SPEC*.md`; the public PHP API breaks (list in the job summary) while `Library::VERSION` stays on the same major (the same minor before 1.0); the package (`src/`, `config/`, `composer.json`) changed while `Library::VERSION` is already tagged; the package changed without a `CHANGELOG.md` change (label `skip-changelog` to waive) |
| **Tests & coverage** | PHPUnit on PHP 8.4 / Laravel 13 with pcov: `--coverage-clover build/logs/clover.xml --log-junit build/logs/junit.xml` | a test fails (`failOnRisky`, `failOnWarning`). The summary shows the counts and the line/method coverage; `build/logs/` is the `test-results` artifact |
| **Compatibility** | PHPUnit on PHP 8.2, 8.3, 8.4 × Laravel 12, 13 (Laravel 13 needs PHP 8.3+), plus PHP 8.2 / Laravel 12 with `--prefer-lowest`. The legs report as `PHP x / Laravel y`; the `Compatibility` job stands for all of them | any leg fails |
| **SonarQube** | restores `test-results`, runs `SonarSource/sonarqube-scan-action` with `sonar-project.properties` | the quality gate fails (`sonar.qualitygate.wait=true`). **Skipped with a note when `SONAR_TOKEN` is not set** (Dependabot runs), so the required check still passes |
| **Build** | `composer archive --format=zip` (what Composer ships: the repository minus the `export-ignore` paths in `.gitattributes`), then a fresh `laravel/laravel` app that installs the archive from a `path` repository | the archive lacks `composer.json`, `LICENSE.md`, `README.md`, `CHANGELOG.md`, `config/sfere.php` or the provider, or ships `tests/`, `docs/`, the tool configs or the internal notes; auto-discovery does not register the package; `sfere:install` / `sfere:test` are missing; `vendor:publish --tag=sfere-config` writes no `config/sfere.php`; the installed `Library::VERSION` differs. The zip is the `sfere-laravel` artifact |

`tests/Unit/Core/ReleaseTest.php` runs `.github/scripts/check-release.php` in every
test job, so a `Library::VERSION` without a dated CHANGELOG section fails a pull
request long before a tag does.

### `release.yml` — release a tag

| Item | Value |
|---|---|
| Trigger | `push` of a tag matching `v*` |
| Gate | the whole `ci.yml` first (`workflow_call`), so a release never ships code that did not pass every PR check |
| Environment | `release` (deployment record; accepts only `v*` refs) |
| Permissions | `contents: write` (the GitHub release) on the release job only |

Composer installs versions straight from git tags, so the tag itself is the
release; there is no registry upload. Steps, in order:

1. `.github/scripts/check-release.php`: the tag is `vX.Y.Z` or `vX.Y.Z-pre.N`,
   equals `Library::VERSION`, and `CHANGELOG.md` has a dated, non-empty
   `## [X.Y.Z] - YYYY-MM-DD` section. The tag must also point at a commit on `main`.
2. `composer archive` of the tagged commit, and its sha256.
3. The tag as an app consumes it: a fresh project requires
   `sfere-io/sfere-laravel:X.Y.Z` from this repository (a `vcs` repository,
   authenticated with the Actions token), and the installed `Library::VERSION`
   must equal the tag.
4. `gh release create vX.Y.Z` with the CHANGELOG section as notes (plus the install
   snippet and the archive's sha256), the archive attached, and `--prerelease` when
   the version has a `-` suffix.
5. When `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` are set, Packagist is told to
   re-read the repository (only needed on packagist.org without its GitHub app).
6. Once the repository is public: packagist.org must list the version within 10
   minutes, and a fresh project with no GitHub token installs it from there and gets
   the same `Library::VERSION`. While the repository is private the step only notes
   why it did not run.

## Releasing

1. On a branch: pick the version (SemVer: a breaking API or wire change is a major,
   a feature a minor, a fix a patch; a wire change also follows sfere-sdk
   `docs/WIRE-CONTRACT.md`). Set `Library::VERSION` in `src/Support/Library.php`;
   it must not be tagged yet. Rename `## [Unreleased]` in `CHANGELOG.md` to
   `## [X.Y.Z] - YYYY-MM-DD`, add a new empty `## [Unreleased]` above it, and
   update the link references at the bottom. Run `composer ci`. Open the PR, get it
   merged.
2. An admin tags the merge commit on `main` and pushes the tag:

   ```sh
   git switch main && git pull
   git tag vX.Y.Z
   git -c credential.helper='!gh auth git-credential' push origin vX.Y.Z
   ```

   Only admins can create `v*` tags (the `release-tags` ruleset), and no one can
   move or delete a tag (`protect-tags`).
3. Watch the `release` run. When it is green, the release is at
   https://github.com/sfere-io/sfere-laravel-sdk/releases.
4. If the run fails, the tag exists and apps can already resolve it: fix forward
   with a new `Library::VERSION`, never by re-tagging.

Pre-releases are `X.Y.Z-rc.N` (or `-beta.N`); the GitHub release is marked as a
pre-release.

## Consuming the package

**Decided 2026-10-20: the package is public on packagist.org** as
`sfere-io/sfere-laravel` (MIT). Apps then install it with nothing but

```sh
composer require sfere-io/sfere-laravel
```

`Library::NAME` (`@fanfinity/sfere-laravel`) does not follow the Composer rename: it is
what every event carries in `context.library.name`, part of the wire contract shared
with the other Sfere SDKs, and changes only together with them.

### Before the repository goes public

packagist.org serves public repositories only. Making this one public publishes its
whole history, so first:

1. **Move the internal documents out.** `NOTES.md`, `SFERE_SDK_SPEC.md` and
   `SFERE_SDK_SPEC.orig.md` name the ingestion vendor and internal hosts, and
   `NOTES.md` points at a file with live credentials in another repository. They
   are export-ignored (they never ship in the package) but they are in the
   repository and in every commit. Move them to a private home (for example
   `sfere-io/sfere-sdk`), delete them here, and rewrite the history so no commit
   carries them (or publish a fresh history from the cleaned tree). Then the
   API contract job's three vendor-name exclusions can go.
2. Make the repository public (Settings → General → Danger Zone).
3. On packagist.org, signed in with the Sfere account, submit
   `https://github.com/sfere-io/sfere-laravel-sdk` at
   <https://packagist.org/packages/submit>, and connect the Packagist GitHub app (or
   the repository webhook) so new tags appear by themselves. Without it, set the
   repository secrets `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` and the release
   workflow notifies Packagist itself.
4. Tag the first release (`v1.0.0`, see [Releasing](#releasing)). The release
   workflow's last step waits for packagist.org to list the version and installs it
   from there.

### Until then

An app that needs the package before it is public adds this repository as a `vcs`
repository and gives Composer a GitHub token with read access to it (a fine-grained
token with *Contents: read*, or a classic token with `repo`), in the developer's global
Composer auth, never in the app:

```sh
composer config --global github-oauth.github.com <token>
```

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/sfere-io/sfere-laravel-sdk" }]
```

Then `composer require sfere-io/sfere-laravel`. CI systems building the app use a
service account's token the same way (`COMPOSER_AUTH`).

## Branch and tag protection

Repository rulesets, all with **no bypass actors** (admins included):

| Ruleset | Target | Rules |
|---|---|---|
| `protect-main` | the default branch | no deletion, no force-push, linear history; pull request required with 1 approval, code-owner review, stale approvals dismissed on push, last push approved by someone else, all threads resolved, squash merge only; required checks `PHP style`, `Static analysis`, `API contract`, `Tests & coverage`, `Compatibility`, `SonarQube`, `Build`, strict (branch up to date) |
| `protect-tags` | all tags | no deletion, no force-push (a tag never moves) |
| `release-tags` | `v*` tags | creation restricted; bypass: repository admins (creating a release tag is the privileged act) |

Repository settings that go with them: squash merge only, branches deleted on
merge, Dependabot alerts and security updates on, Actions limited to GitHub-owned
and verified creators plus `actions/*`, `shivammathur/setup-php@*` and
`SonarSource/sonarqube-scan-action@*`, default `GITHUB_TOKEN` read-only, Actions
may not create or approve pull requests.

`.github/CODEOWNERS` lists the sensitive paths (workflows, hooks, `composer.json`,
the tool configs, `src/Support/Library.php`, `config/`, the policy docs); a
maintainer must approve changes to them.

## Dependencies

A library commits no `composer.lock`: apps resolve the package's constraints
together with their own. Two consequences:

- CI resolves fresh on every run (`composer update`), so the newest versions the
  constraints allow are always what is tested, and the `Compatibility` job's
  `--prefer-lowest` leg tests the oldest.
- Dependabot (`.github/dependabot.yml`, weekly) raises constraints and pinned
  action SHAs; `composer audit` in **Static analysis** fails on an advisory for
  anything resolved.

## Local counterparts

| Gate | Locally |
|---|---|
| Style, static analysis, tests | `composer ci` (Pint, PHPStan, PHPUnit) |
| Formatting | `composer format` |
| Backward compatibility | `composer require --working-dir=/tmp/bc roave/backward-compatibility-check`, then `/tmp/bc/vendor/bin/roave-backward-compatibility-check --from=origin/main` |
| Coverage report | `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html build/coverage` (needs pcov or Xdebug) |
| Archive | `composer archive --format=zip --dir=build` from a clean checkout (the archive honours only `export-ignore`, so a local `vendor/` would be included) |
| Pre-commit hook | `git config core.hooksPath .githooks` once per clone: Pint on the staged PHP and the vendor-name check |

## Secrets and variables

| Name | Where | Used by |
|---|---|---|
| `SONAR_TOKEN` | repository secret (**not set yet**) | the `SonarQube` job. SonarQube Cloud project `sfere-io_sfere-laravel-sdk` in organization `sfere-io` |
| `PACKAGIST_USERNAME`, `PACKAGIST_TOKEN` | repository secrets (optional, not set) | the release's Packagist notification |
| `GITHUB_TOKEN` | automatic | the release's consumer install and the GitHub release |

No deploy keys, no personal tokens, no cloud credentials.

## Not yet in CI

- **Staging delivery.** `php artisan sfere:test` against the real ingestion
  endpoint needs a staging host and server key; it is run by hand before a release
  that changes delivery or the payload.
