# 9. Audit log

Every request your app handles is recorded, not opt-in and not per-route,
to its own SQLite database, separate from your application data.

## What's recorded

`Kip\RequestLog::log()` (`src/RequestLog.php`) writes one row per request
to a `requests` table:

| Column | Meaning |
|---|---|
| `id` | autoincrement primary key |
| `created_at` | ISO 8601 timestamp (`date('c')`) |
| `method` | `GET`, `POST`, ... |
| `path` | the request path (control characters and terminal-escape sequences stripped, see below) |
| `status` | the response status code, including error responses |
| `duration_ms` | wall-clock time from the start of `App::handle()` to the response |
| `ip` | proxy-resolved if `trusted_proxy` is on (see [chapter 6](06-security.md)) |
| `user_id` | the logged-in user's id, or `NULL` for a guest |

`App::handle()` calls `$this->requestLog?->log(...)` *after*
`cachedProcess()` returns, wrapping the whole request including error
responses, a 500 gets logged the same as a 200. Logging is entirely
opt-in at the config level: omit `log_db` from `config.php` and
`RequestLog` is never constructed, so nothing is written and nothing is
queried.

## `logs.sqlite` is a separate database, on purpose

The audit log lives in its own SQLite file (`app/logs.sqlite`), not a
table alongside your application data in `app/data.sqlite`), operational
logs stay separate from application data. This means:

- A backup of your application data (`app/data.sqlite`) never has to
  carry request-level PII along with it.
- Heavy log write volume doesn't compete for the same file's WAL with your
  application's own reads and writes.
- You can prune or even delete the logs database entirely without
  touching application data.

See [chapter 10](10-deployment.md) for what to back up and what not to.

## Retention

Configured per app via `log_db.retention_days` in `config.php` (default
30 if `log_db` is set but the key isn't):

```php
'log_db' => ['dsn' => 'sqlite:' . __DIR__ . '/app/logs.sqlite', 'retention_days' => 30],
```

Pruning happens two ways:

1. **Automatically**, every time `bin/kip migrate` or `bin/kip logs` runs
   (`RequestLog::pruneToRetention()`). So an app that's redeployed or
   checked on regularly never accumulates unbounded rows without you doing
   anything extra.
2. **On a schedule**, via `bin/kip logs:prune --days=N` (see
   [chapter 8](08-cli.md)). Necessary for an app that might run quietly
   for weeks between deploys:

```
0 3 * * * php /path/to/app/bin/kip logs:prune --days=30
```

## Privacy note

The log stores IP address and user id indefinitely *within* the retention
window. There's no anonymization or hashing of either. Treat
`logs.sqlite` as containing personal data for as long as `retention_days`
keeps a row around, and set retention (or the cron prune) to whatever
window your privacy policy and applicable law actually require. The
framework enforces a retention *mechanism*; it doesn't have an opinion on
what number you should configure for your jurisdiction.

## Terminal-escape sanitization

`RequestLog::log()` strips the request path before storing it:

```php
$path = preg_replace('/\x1b\][^\x07]*\x07|[\x00-\x1F\x7F]/', '', $request->path);
```

This removes full ANSI/OSC escape sequences (`ESC ] ... BEL`, the kind that
can rewrite a terminal's title or inject fake prompt text) as a unit, plus
any stray control bytes. Protecting whoever runs `bin/kip logs` later and
pipes the output straight to a terminal from a maliciously crafted request
path doing something to *their* terminal, not just an XSS-style concern
against a browser.

## How it works

Logging is **best-effort**: `RequestLog::log()` wraps its own insert in a
`try`/`catch` and calls `error_log()` on failure rather than letting a
logging error take down the response being served. A full disk or a
locked `logs.sqlite` degrades to "this one request didn't get logged," not
a 500 for your visitor.
