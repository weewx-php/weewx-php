# Demo theme review

Date: 2026-09-05. Scope: `public/index.php`, `public/assets/demo.css`,
`themes/demo/` and `tests/Unit/Frontend/DemoThemeTest.php`.

- 25 frontend tests with 123 assertions passed, including 3 new demo tests.
- PHPStan at the highest level reported no errors for the theme, entry point
  and demo tests.
- The project formatter reported no issues in the new PHP files.
- Browser checks: desktop and 390 px, both periods, expandable table,
  no horizontal overflow and no console errors.
- HTTP checks: status 200, HTML content type, CSP and safe default even when
  `range` is an array instead of a scalar parameter.
- Project-wide lint failed during this review because of changes outside this
  scope (formatting and missing types). Those files were not changed for the theme.

## Security Review

Security-Sensitive: YES. Reviewed By: Codex, main agent.
OWASP categories checked: 10/10; no open findings in the reviewed scope.

| Area | Result |
|---|---|
| Injection | No custom SQL or shell calls; fixed tag recipes |
| Authentication | Public weather display, no accounts or sessions |
| Sensitive data | No configuration or internal paths in page error messages |
| XML | No XML processing |
| Access | Webroot `public/`; entry point does not serve databases or configuration |
| Configuration | CSP without scripts, `nosniff`, fixed include paths |
| XSS | Text and attributes escaped; SVG coordinates from numbers; test for HTML special characters |
| Deserialization | No user-controlled deserialization |
| Dependencies | No new packages or external assets; runtime manifest contains only PHP and extensions |
| Logging | Errors logged server-side, no stack traces in the template |

Dependency Audit: existing runtime manifest rechecked; no Composer runtime
packages. The theme does not extend the previously completed audit of unchanged
development dependencies.

Security Review Status: PASS.
