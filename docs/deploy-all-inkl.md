# Update the core on All-Inkl

Run `scripts/deploy_all_inkl.py` on your computer with Python 3.10 or newer.
It uses the Python standard library; no packages or Python installation on the
web host are needed. [All-Inkl supports explicit FTPS](https://all-inkl.com/en/support).
The script requires encrypted control and data connections, a trusted server
certificate, MLSD and Unix LIST directory listings, and rename-overwrite support.
There is no unencrypted fallback.

This is an updater for an **existing installation**. The FTP account must be able
to access the installation root containing `src/` and `public/`. Set the domain's
document root to `public/`. Do not use the public directory as the deployment root.
The server still needs PHP 8.1+ with SQLite3, JSON and Phar enabled.

## Configure once

From the core checkout in PowerShell:

```powershell
New-Item -ItemType Directory -Force .deploy
Copy-Item deploy/all-inkl.example.json .deploy/all-inkl.json
```

Edit `.deploy/all-inkl.json`:

```json
{
  "host": "your-ftp-server.kasserver.com",
  "username": "your-ftp-username",
  "remote_root": "/weewx-php",
  "port": 21,
  "timeout": 30
}
```

Use the FTP server hostname and username from KAS. `remote_root` is the absolute
path **as seen by this FTP account**, which can differ from the server's filesystem
path. If the account is restricted to the installation root, use `/`. No trailing
slash is needed for other paths. Symbolic links in deployment paths are rejected.

The password is prompted without echo. For unattended runs, supply it through the
`WEEWX_DEPLOY_PASSWORD` environment variable using your secret manager. Passwords
in the JSON configuration or command-line arguments are not supported. `.deploy/`
is ignored by Git and excluded from uploads. English is the default output
language; use `--language de` for German.

## Preview and update

```powershell
python scripts/deploy_all_inkl.py --dry-run --list
python scripts/deploy_all_inkl.py --apply
```

On Windows, `py -3` can replace `python`; on Linux or macOS, use `python3`.
A custom configuration path can be passed with `--config PATH`.

Without `--apply`, the script only checks the local payload. It does not connect,
request a password or compare with the server. The file list includes all local
core files, including uncommitted changes. Update the local checkout to the
intended release and run its Docker checks before deployment.

The update compares file contents, stages changed files and their backups, then
reads them back to verify the transfers. It publishes files with FTP rename and
verifies the published content. Identical files are skipped. Exit code `0` means
success or preview; a failed update returns `1` (invalid CLI arguments return `2`).

## Update scope

The payload contains runtime files under `src/`, `resources/`, `public/` and
`themes/basic/`, plus `frontend.php`, `LICENSE`, `bin/weewx-php`, `bin/worker.php`
and `bin/ingest-router.php`. Hidden files and unsupported file extensions are
excluded. Files are limited to 16 MiB each and 64 MiB per payload or downloaded
set of current core files.

The following stay on the server unchanged:

- `weewx-php.conf`, databases, logs and everything under `data/`.
- Installed extensions and themes other than `basic`.
- Existing `.htaccess` files and custom files outside the payload.
- Files from older releases that no longer exist in the local checkout.

Core files and the basic theme are replaced when their contents differ, including
server-side edits to those files. Dependencies of separately installed packages
are managed with those packages. The script does not change configuration,
install extensions, remove old bundled themes, run migrations or restart PHP.

Each update keeps a directory under `.weewx-deploy/` **outside the public document
root**, containing a manifest and copies of the files it replaces. A deployment
lock prevents simultaneous runs of this script. Allow space for staged files and
backups; backups are retained until you remove them manually.

FTP rename replaces individual files; the entire release is not atomic. Use a
maintenance window and pause scheduled workers/ingest while publishing a release
that cannot tolerate mixed versions. Afterward check the public page, admin and
scheduled processing. If PHP OPcache has timestamp validation disabled, arrange
its reset through the hosting controls; this script does not reset OPcache.

## Failed updates and recovery

A staging failure leaves the existing live files unchanged. It can leave empty
directories and partial staging/backup files. During publication, the script
attempts to restore every file whose replacement was attempted, including newly
added files. A disconnected session, process termination or failed restore can
require manual recovery. The console prints the relevant backup directory.

If recovery is required:

1. Stop concurrent deployments and use the same maintenance window as above.
2. Open the printed `.weewx-deploy/<release>/manifest.json` with an FTPS client.
   A manifest exists before publication begins. An incomplete staging directory
   without a manifest has not been published by this script.
3. For each manifest entry with `existed: true`, copy `backup/<path>` back to
   `<path>` under the installation root. For `existed: false`, remove the new
   file at `<path>` if it exists. Keep configuration and data untouched.
4. Check the installation before removing `.weewx-deploy/lock`. Never remove a
   lock while another deployment is still running.

If the connection dropped, a lock may remain even when no live files changed.
Inspect the backup directory and deployment output before clearing it. Once an
update is verified, old release directories can be removed with an FTPS client.
Keep `.weewx-deploy/.htaccess` and any active lock.

## Docker verification

```powershell
docker compose -f tests/docker/compose.yml build deploy
docker compose -f tests/docker/compose.yml run --rm deploy
```

The tests use a local FTPS server inside an isolated container, trusted test
certificates, temporary files and injected transfer failures. They do not connect
to an All-Inkl account. A dependency audit requires network access:

```powershell
docker run --rm --tmpfs /tmp -e XDG_CACHE_HOME=/tmp/cache weewx-php-deploy-tests pip-audit --progress-spinner off
```
