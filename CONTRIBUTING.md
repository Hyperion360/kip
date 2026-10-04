# Contributing

The quality gate is the first thing to know:

    PATH="/opt/homebrew/bin:$PATH" composer check

Any PHP 8.3 or newer binary on your PATH works (the prefix above is just
one machine's way of selecting it). `composer check` runs static analysis
(PHPStan level 6 over `src/`), the docs fidelity check, and the test
suite, stopping at the first failure. A pull request that does not pass it
does not get reviewed; run it before every commit you send.

Setup: clone, `composer install`, run the gate. The suite needs only
SQLite and runs in seconds; there is no JavaScript, no build step, and no
other tooling to install.

What the gate enforces beyond the tests:

- Docs are checked against reality. Relative links must resolve, every
  `src/...` path mentioned must exist, file-count claims must match the
  real count, and CLI examples must use commands `bin/kip` really has.
  If you change code the docs describe, change the docs in the same pull
  request.
- `composer lint` holds `src/` to PHPStan level 6; the two ignores in
  `phpstan.neon.dist` are documented inline, not silent.
- Every framework class is `final`, and the runtime `require` stays
  `php >= 8.3` plus `ext-pdo`: zero runtime dependencies is a contract.

Writing: match the existing voice. Plain sentences, concrete outcomes,
real numbers over adjectives, no em dashes anywhere in a committed file.

Bugs and small fixes: an issue with a reproduction, then a pull request
with a test that fails before the fix and passes after. Design changes
(new batteries, anything on the public API): open the issue first;
pre-1.0 still means a deprecation cycle before a break, per the
[versioning contract](docs/versioning.md).

## Releasing

Changes land with a changelog fragment, never a hand-edited `CHANGELOG.md`:
each pull request adds exactly one `changelog.d/<slug>.md` file holding one
line like `Fixed: the blog caps guest comment floods with the rate limiter`.
The type must be one of Added, Changed, Fixed, Security, Deprecated, or
Removed. Parallel branches each write their own fragment, so no two of them
ever touch the same changelog line.

A release is `PATH="/opt/homebrew/bin:$PATH" php bin/release <x.y.z>`: the
script combines the fragments into a dated Keep a Changelog section, deletes
them, commits, and writes an annotated tag. The maintainer picks the number
(patch for fixes, minor for features; during 0.x a minor may break, loudly,
per the [versioning contract](docs/versioning.md)) and reviews the printed
notes before anything is pushed. Pushing the tag
(`git push origin main --tags`) is a separate, explicit step. A fix that
must ship against an older release lands on `main` first and is
cherry-picked to a branch cut from that release's tag; nothing merges from
a release branch back to `main`.
