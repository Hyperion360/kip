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
