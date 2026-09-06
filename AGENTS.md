# Project rules

- Keep UI and product copy concise and functional. Avoid explanatory filler, marketing copy and instructional microcopy unless explicitly requested or technically necessary.
- Always run tests in Docker across all projects in this workspace, including extensions and themes. Start Docker if it is not running. Do not use host runtimes as a substitute for Docker tests.
- This is an international project. Write GitHub content, documentation, commit/PR/issue text and code comments in English.
- Design every new or changed feature for multiple languages from the start. English is the default language; keep translated UI strings in locale resources and provide an English fallback.

Core checks: `docker compose -f tests/docker/compose.yml run --rm lint`, `unit`, `frontend-js` and `conformance`. Build the test image after Dockerfile or Composer dependency changes.

Deployment script checks: build the `deploy` service and run `docker compose -f tests/docker/compose.yml run --rm deploy`. The default `tests/run.sh` includes this suite.
