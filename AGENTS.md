# Kip: agent instructions

Kip is a plain-PHP, batteries-included, server-rendered framework: PHP 8.3+,
zero runtime dependencies, zero JavaScript, SQLite by default. Written and
maintained by Danilo Stern-Sapad (https://danilosapad.com/) on behalf of
Hyperion360 Inc (https://hyperion360.com/). MIT licensed.

This file is committed and is part of the product (source dists exclude
it; it serves repository checkouts): it is the contract coding
agents should follow when working in this repository. Companion reading:
[CONTRIBUTING.md](CONTRIBUTING.md) (the quality gate and how to send changes),
the [user guide](docs/guide/README.md), [architecture](docs/architecture.md),
[versioning](docs/versioning.md), and
[building with AI agents](docs/guide/16-building-with-ai.md).

## Repository layout

- `src/` + `tests/`: `kip/framework`, the framework core (the root composer package)
- `skeleton/`: `kip/skeleton`, the minimal app template, auth pre-wired
- `examples/blog/`: the finished tutorial app
- `docs/`: tutorial, guide chapters, architecture, versioning, design decisions
- `scripts/check-docs.php`: docs fidelity checker (must pass after any docs change)

## Hard constraints (never violate)

- Root `composer.json` `require` stays exactly `{"php": ">=8.3", "ext-pdo": "*"}`.
  Zero runtime dependencies is a contract, not a current state.
- Zero JavaScript: no build step, no bundler, no npm, ever. Every feature works
  with scripting disabled.
- Every framework class in `src/` is `final`, except `Kip\Migrations\Migration`,
  the abstract base your own migrations extend.
- `skeleton/bin/kip` and `examples/blog/bin/kip` must stay byte-identical.
- No references to other frameworks, codebases, or repos in code, comments,
  docs, or commit messages. Category-level comparisons ("full-stack
  frameworks", "micro-frameworks") are allowed in `README.md` only.
- Never commit planning docs, research notes, QA reports, or vendored
  third-party trees.
- The framework is pre-1.0 with a written stability promise: boring upgrades,
  no rewrites. Breaking changes need a deprecation cycle first.

## Commands

- Full gate (run this before reporting a task complete):
  `PATH="/opt/homebrew/bin:$PATH" composer check`
  Runs static analysis, then docs fidelity, then the suite, stopping at the
  first failure.
- Individually: `composer lint`, `composer check-docs`, `composer test`
  (if the `php` on your PATH is older than 8.3, prepend a newer PHP's bin
  directory to PATH; the prefix shown above is one machine's way of doing that)
- App CLI (from inside `skeleton/` or `examples/blog/`): `php bin/kip`
  (`kip serve` binds localhost:8080, guide [chapter 8](docs/guide/08-cli.md))

## Writing style (applies to every committed file)

- No em dashes, anywhere, in any committed file. Use a comma, colon, period,
  semicolon, or parentheses instead.
- Sentence discipline: strong nouns and verbs, one insight per paragraph, no
  filler. Real numbers and concrete outcomes over adjectives.
- Banned words: leverage (as a verb), synergy, empower, revolutionize,
  supercharge, delve, unlock (metaphorical), elevate, seamless, robust.
- Answer first, explain second. Headers must be fully descriptive on their own.
- Guide chapters and reference docs: neutral, helpful, precise, task-oriented.
- When editing prose, never change facts, numbers, names, or the logical
  relationship between statements. Edit wording, not content.

## Performance contract (query budget)

Every rendered page executes AT MOST ONE query against the content database:
multi-entity pages are assembled in that one query with LEFT JOINs,
GROUP_CONCAT, conditional aggregation, or UNION ALL folding. Writes are
batched inside ONE transaction per action. Enforcement is a test, not a
convention: query-budget tests fail any page render that exceeds the budget.
The full statement, the SQL patterns, and the EXPLAIN standards are
[chapter 15](docs/guide/15-performance-contract.md).
