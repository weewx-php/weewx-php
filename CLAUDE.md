# weewx-php

PHP 8.1+ application for WeeWX archives on shared web hosts. WeeWX 5.5 databases must remain fully WeeWX-compatible.

Phase: alpha (pre-1.0)

## Project rules

- Change schemas and formats directly. Do not add compatibility shims or migrations.
- Add small smoke or regression tests for the main path of a fix.
- Keep UI and product copy concise and functional.
- Write code comments, documentation and GitHub content in English.
- Put translated UI strings in locale resources, with English as the fallback. Supply English and German strings for changes.
- Run all tests in Docker, including extensions and themes. Start Docker if needed. Never substitute host runtimes.

## Checks

Run from the repository root:

- `docker compose -f tests/docker/compose.yml run --rm lint`
- `docker compose -f tests/docker/compose.yml run --rm unit`
- `docker compose -f tests/docker/compose.yml run --rm frontend-js`
- `docker compose -f tests/docker/compose.yml run --rm conformance`

After Dockerfile or Composer dependency changes, rebuild the affected test image with `docker compose -f tests/docker/compose.yml build`.

After changes to `scripts/deploy_all_inkl.py`, build and run the deployment tests:

- `docker compose -f tests/docker/compose.yml build deploy`
- `docker compose -f tests/docker/compose.yml run --rm deploy`

`tests/run.sh` runs lint, unit, frontend-js, deploy and conformance.

## Layout

- `src/`: application code.
- `public/`: HTTP entry points and browser assets.
- `resources/admin/locales/`: administration translations.
- `tests/Unit/`: PHP regression tests.
- `tests/conformance/`: comparisons with WeeWX.
- `tests/deploy/`: deployment script tests.
