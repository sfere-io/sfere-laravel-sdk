# Releasing `fanfinity/sfere-laravel`

Composer installs versions straight from git tags: Packagist reads the GitHub repository, so a
pushed tag **is** the release. `.github/workflows/release.yml` checks the tag, runs the full test
matrix and publishes the GitHub Release.

## One-time setup

1. **Create the GitHub repository** (e.g. `fanfinity/sfere-laravel`) and push:

   ```bash
   git remote add origin git@github.com:fanfinity/sfere-laravel.git
   git push -u origin main
   ```

   The `tests` workflow runs on that push. Protect `main` and require it to pass.

2. **Choose how the package is distributed.** It decides the license (spec §15):

   | | Public (packagist.org) | Private |
   |---|---|---|
   | Repository | public | private |
   | `license` in composer.json / LICENSE.md | MIT (as now) | change to `proprietary` |
   | Where | Submit the repo URL at <https://packagist.org/packages/submit>, then connect the Packagist GitHub app so tags appear automatically | [Private Packagist](https://packagist.com), or a `vcs` repository in each app (below) |
   | Apps install with | `composer require fanfinity/sfere-laravel` | the same, after adding the repository and a token |

   Private without Private Packagist, in each app's `composer.json`:

   ```json
   "repositories": [{ "type": "vcs", "url": "https://github.com/fanfinity/sfere-laravel" }]
   ```

   and `composer config --global github-oauth.github.com <token with repo read access>`.

3. **Optional:** if the Packagist GitHub app is not connected, add the repository secrets
   `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` (Packagist profile → "safe" API token). The release
   workflow then notifies Packagist itself.

4. Once the URL is final, add `"homepage"` and `"support": {"issues": …, "source": …}` to composer.json.

## Every release

1. Pick the version (SemVer): breaking API or wire change → major; new feature → minor; fix → patch.
   Changing what goes on the wire must also follow sfere-sdk `docs/WIRE-CONTRACT.md`.
2. Bump `Sfere\Support\Library::VERSION` in `src/Support/Library.php`. Every event reports it in
   `context.library.version`.
3. Move the `## [Unreleased]` notes in `CHANGELOG.md` into `## [X.Y.Z] - YYYY-MM-DD`.
4. Run `composer ci`. `tests/Unit/Core/ReleaseTest.php` fails if steps 2 and 3 disagree.
5. Merge to `main`, then tag and push:

   ```bash
   git tag vX.Y.Z
   git push origin vX.Y.Z
   ```

   Pre-releases use `vX.Y.Z-rc.1` (the GitHub Release is marked as a pre-release).

The workflow fails, and nothing is published, when the tag is not `vX.Y.Z`, does not equal
`Library::VERSION`, has no dated CHANGELOG section, or any test leg fails. If it fails, delete the
tag (`git push --delete origin vX.Y.Z && git tag -d vX.Y.Z`), fix, and tag again. **Never move a tag
that Packagist has already published**: apps that installed it would get different code under the
same version. Release a new patch version instead.
