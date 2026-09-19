# examples/blog

The tutorial's end state: a complete, runnable blog app built on Kip:
posts with pagination, comments (guest-facing, origin-verified), auth with
login throttling, the page cache with table-tag invalidation, and ETag/304
support.

## Run

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
KIP_ENV=dev php -S localhost:8090 -t public
```

Then open http://localhost:8090.

> **Note:** until `kip/framework` is published to Packagist, this app's
> `composer.json` resolves it via a local path repository two levels up
> (`../../`). That's why `composer install` must be run with this checkout
> still sitting inside the framework repo it came from.

This app is the end state of [`../../docs/tutorial.md`](../../docs/tutorial.md)
, follow that to build it yourself step by step, or see the
[full guide](../../docs/guide/README.md) for reference material on
everything used here.
