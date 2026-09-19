# kip/skeleton

The starting point for a new Kip application: routing, database, sessions,
CSRF, a working auth battery (login, logout, throttling, password reset),
and the admin panel are already wired up. No blog, no demo content, just
the minimum scaffolding plus a welcome page.

## Quickstart

```bash
composer install
php bin/kip migrate
```

Then create an account (run on its own. It prompts for the password with
the echo off, keeping it out of shell history) and start the dev server:

```bash
php bin/kip user:create you@example.com
```

```bash
php bin/kip serve
```

Then open http://localhost:8080. Log in and the home page shows a
"Log out" button. To make the account an admin (the only way admin access
is ever granted), add `--admin`:

```bash
php bin/kip user:create you@example.com --admin
```

> **Note:** until `kip/framework` is published to Packagist, this skeleton's
> `composer.json` resolves it via a local path repository (`../` relative to
> this directory). That's why `composer install` must be run with this
> skeleton still sitting next to the framework checkout it was cloned from.

## What's included

- `app/src/Controllers/HomeController.php`, renders the welcome page.
- `app/src/Controllers/AuthController.php`: login/logout, CSRF-protected,
  with login throttling, plus the password-reset flow
  (forgot/remind/reset/confirm; dev mail lands in `app/mail.log`).
- `app/views/`: `layout.php`, `home/index.php`, `auth/login.php`,
  `auth/forgot.php`, `auth/reset.php`.
- `app/migrations/`: `users` (with `is_admin`), `login_attempts`, and
  `password_resets` tables.
- The admin panel is enabled in `config.php`, log in with an `--admin`
  account and visit `/admin` (see the
  [admin chapter](../docs/guide/12-admin-panel.md)).
- `public/`, `bin/kip`, `config.php`, same shape as the framework's CLI and
  front controller, pointed at this app's own `vendor/`. `bin/kip` also
  covers `backup` and dev auto-migrate on `serve`.

## Next steps

Follow [`../docs/tutorial.md`](../docs/tutorial.md) to build your first
feature on top of this skeleton, then browse the
[full guide](../docs/guide/README.md) for the reference material the
tutorial doesn't cover. For a complete worked example (the tutorial's end
state), see [`../examples/blog`](../examples/blog).
