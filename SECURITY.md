# Security Policy

Kip's security posture is documented in the [user guide](docs/guide/README.md):
login throttling, CSRF, session hardening, audit logging, and the page
cache's poisoning defenses are covered chapter by chapter.

## Reporting a vulnerability

Report privately through GitHub's "Report a vulnerability" flow on this
repository's Security tab. Do not open a public issue for anything you
believe is a vulnerability. You will get an acknowledgement within a week;
disclosure timing is coordinated with you, and fixed releases credit
reporters who want the credit.

## Scope

The framework (`src/`), the skeleton app, and the bundled example are in
scope. Applications built with Kip are their authors' responsibility:
their controllers, views, config, and database contents are not this
project's attack surface. Reports about apps built on Kip go to those
apps' owners. The supported version is the latest release; the framework
is pre-1.0 and security fixes land in the newest code, not backports.

## What a good report includes

The version, the PHP version, a minimal reproduction (a route, a request),
and your read of the impact. Reports that arrive with a failing test
against `tests/` get fixed fastest; the suite runs in seconds and needs
only SQLite.
