# Update the core from GitHub

For a new server installation, download `weewx-php-core.zip` from the release
assets and follow its `INSTALL.md`. This archive contains only the core, Basic
theme, configuration example and server rules. GitHub's automatic source
archives contain the development checkout; use the named release asset for
installation. `SHA256SUMS` covers both the installation ZIP and admin update JSON.

Open **Settings → Core update**, select **Check for updates**, then **Install
update**. The version link opens the release notes on GitHub. Checking and
installing are separate actions; checking does not install anything.

The updater must first be installed through your existing deployment method.
On All-Inkl, use the [core deployment guide](deploy-all-inkl.md). Subsequent
published releases can be installed from the admin.

The PHP account needs write access to the core directories and the private
`.weewx-deploy/` directory in the installation root. Keep the document root at
`public/`. Outbound HTTPS to GitHub and its release asset hosts must work.
Read-only installations can still use an external deployment method.

## Choose stable or beta releases

**Core update channel** defaults to **Stable**. To test beta versions, select
**Beta**, save the settings, then check for updates. The selection is stored in
`[Admin] update_channel` as `stable` or `beta`.

- **Stable** uses the latest published stable GitHub release.
- **Beta** selects the highest version among stable and beta releases in the
  latest 100 published releases. A stable version supersedes its beta versions.

Switching back to Stable does not downgrade an installed beta. The next newer
stable release will be offered. Draft releases and tags without a published
release are never offered.

## What an update changes

The updater replaces the core and bundled basic theme. Configuration, weather
databases, logs, separately installed themes and extensions remain in place.
Existing `.htaccess` files and files outside the release package are preserved.
Custom edits to files shipped by the core are replaced.

The release asset and each packaged file are checked before installation. PHP
version, required extensions, file paths and PHP syntax are checked locally.
The release is checked again when installing; if its asset changed since the
page was loaded, check for updates again.

Existing PHP requests and workers hold a shared runtime lock. Installation
requires exclusive access; retry after active work finishes if the server is
busy. New requests return HTTP 503 during the file exchange. The updater saves
the replaced files before publishing and invalidates their OPcache entries.

## Publish a release

1. Set `Version::STRING` in `src/Version.php` to the release version.
2. Commit and push the changes to `main`, then wait for **CI** to pass.
3. Create and push the matching Git tag on that commit. For example:

   ```sh
   git tag -a v0.2.0-beta.1 -m "Release v0.2.0-beta.1"
   git push origin v0.2.0-beta.1
   ```

4. Wait for **Core release** to finish. It publishes the GitHub release with
   generated release notes, `weewx-php-core.json`, `weewx-php-core.zip` and
   `SHA256SUMS`. The tag
   determines whether GitHub marks it as a pre-release.

| Channel | Version in PHP | Git tag | GitHub Pre-release |
| --- | --- | --- | --- |
| Stable | `0.2.0` | `v0.2.0` | Off |
| Beta | `0.2.0-beta.1` | `v0.2.0-beta.1` | On |

The tag and version must match. Versions use `major.minor.patch` with an optional
`-beta.N` suffix. Each number has at most four digits and no leading zeros.
Create the release by pushing its tag; do not publish it manually beforehand.

**CI** runs on branch pushes and pull requests. **Core release** invokes the same
Docker checks on the tagged commit: lint and PHPStan, PHPUnit, frontend tests,
deployment tests and WeeWX conformance. A failed check blocks packaging and
publication. The package is then installed in a temporary core and booted in a
separate PHP process before it can be published.

The publishing job uses that tested artifact. It uploads to a draft, verifies
the GitHub asset digest and size, and checks that the tag still identifies the
tested commit before publishing. Beta releases never become **Latest**; stable
releases use GitHub's version-based latest selection. Build and test jobs have
read-only repository permissions; only the publishing job can write releases.

If publication fails after creating a draft, inspect the failed step. Delete
only that unpublished draft and rerun the workflow after correcting the cause.
Published releases and assets are never overwritten. Changes to package contents
require a new version and tag.

For repository enforcement, require **Docker checks** in the `main` branch
ruleset and restrict creation, updates and deletion of `v*` tags to release
maintainers. These rules are configured in GitHub repository settings separately
from the workflows. The release workflow's checks run even without those rules.

For a manual build, run the existing Docker image and write outside the
read-only repository mount:

```sh
mkdir -p dist
docker compose -f tests/docker/compose.yml run --rm \
  -v "$PWD/dist:/out" unit \
  php scripts/build_core_release.php /out/weewx-php-core.json v0.2.0-beta.1
```

Give the container account write access to `dist/`. The package is JSON with
base64 file contents and SHA-256 hashes; it contains no installation secrets.
The builder reads PHP requirements from `composer.json` and validates its own
output. Package size is limited to 16 MiB, with at most 8 MiB of decoded files.
GitHub supplies the asset digest used by the downloader. See the
[GitHub release asset API](https://docs.github.com/en/rest/releases/assets).

## Recover an interrupted update

Replaced files and their manifest remain under `.weewx-deploy/core-<id>/`.
If file replacement fails, the previous files are restored. If the PHP process
is interrupted, the next request restores the pending transaction before
loading application code. Recovery uses a saved copy of the previous installer.

If recovery cannot read or restore a backup, requests remain unavailable rather
than loading an incomplete core. Inspect the server error log and
`.weewx-deploy/pending.json`. Keep its transaction directory until recovery is
complete. Take the site and workers offline before manually restoring files or
using the external deployment recovery procedure. Do not remove `pending.json`
to bypass an incomplete update.

Completed backup directories can be archived or removed by the operator after
the updated installation has been checked. Automatic recovery concerns core
files; database backups remain a separate operation.
