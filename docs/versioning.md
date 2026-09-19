# Versioning and stability

Kip is pre-1.0, and this page is the written promise about what that
does and doesn't mean. It's a launch requirement, not an afterthought:
the deliverable is "CHANGELOG.md, semver policy, and the written
stability promise, 'boring upgrades, no rewrites' as a public
commitment." This page is that policy.

## The promise

**Upgrades are boring, and Kip is never rewritten under your feet.**

"Boring" means upgrading Kip is two steps: run `composer update`, then
read [`CHANGELOG.md`](../CHANGELOG.md). A fix release is exactly what it
says. A release that changes behavior tells you precisely what moved:
a renamed config key, a changed method signature, and nothing else.

"No rewrites" means the 19-file core you can read in an afternoon stays
recognizably the same codebase across versions. New batteries get
added; the existing ones keep working the way the guide says they do.
The kernel is not going to be replaced by a new architecture wearing
the same package name.

## Version numbers, plainly

Kip follows semantic versioning (semver): versions look like
`MAJOR.MINOR.PATCH`, and the numbers communicate what a release is
allowed to contain. Today the MAJOR is `0`, and during 0.x the MINOR is
the number that matters:

- **A MINOR bump (0.2.0 → 0.3.0) may include breaking changes.**
  Removing or renaming something on the documented surface is allowed
  in a MINOR release, and comes with a migration note in the CHANGELOG.
- **A PATCH bump (0.2.0 → 0.2.1) is fixes only.** Bug fixes and
  security fixes; nothing you could depend on changes.

Both kinds have already happened, so this isn't hypothetical:

- `0.2.0` was a MINOR bump and it broke things deliberately: the project
  was renamed to Kip, which changed the namespace, the CLI name, the
  environment variables, and the cache response headers. Allowed at
  MINOR, and spelled out in its CHANGELOG entry.
- `0.1.1` was a PATCH bump: eleven security and correctness fixes, zero
  breaks.

After 1.0, standard semver applies in full: breaking changes then
require a new MAJOR version: a deliberate, announced event, never
something hidden inside a routine `composer update`.

## What counts as the public API

The public API is the documented surface:

- the `config.php` keys (guide chapter 1),
- the classes and methods the guide documents (routing, controllers,
  views, database, security, performance, chapters 2–7),
- the `bin/kip` CLI commands and their exit codes (chapter 8),
- the environment variables (`KIP_ENV`, `KIP_TRUSTED_PROXY`).

Everything else is internal, and may change in any release. Because
every class in `src/` is `final`. The single exception is the abstract
`Migration` base your migrations extend. "internal" is enforced, not
just requested: you cannot subclass or override your way into an
undocumented dependency, so Kip can refactor internals freely without
breaking any app that stayed on the documented surface. If the guide
doesn't document it, don't build on it.

## Deprecations

When something on the documented surface has to change:

1. It is **soft-deprecated for one full MINOR release**: it keeps
   working, the guide flags it as going away, and the CHANGELOG names
   its replacement.
2. It is **removed in the next MINOR release**, and the removal is
   listed in the CHANGELOG with the migration path.

No removal ships unlisted. Upgrade MINOR-by-MINOR, read the CHANGELOG
each time, and a deletion will never surprise you.

## What an upgrade looks like in practice

Your app's `composer.json` pins something like `^0.2`. That constraint
accepts `0.2.1`, `0.2.2`, … but not `0.3.0`, the policy in mechanical
form: PATCH releases (fixes only) flow to you automatically with
`composer update`; MINOR releases (which may break) are opt-in, taken
deliberately after reading the CHANGELOG. Kip's own apps (`skeleton/`,
`examples/blog/`) carry the same `^0.2` constraint (`^0.2@dev` until
the first tag is published), so every release has to survive the
upgrade path it prescribes.

## The road to 1.0

1.0 is a claim that the surface is finished. It won't happen until:

- **the batteries are complete**, the launch battery list (admin
  panel, file storage, background jobs, and the rest of the v0.3+ plan)
  has either shipped or been explicitly cut and documented as not
  coming;
- **two real apps run on it**, `examples/blog/` plus a second,
  non-demo application in real use;
- **an upgrade path has actually been exercised**, at least one MINOR
  release has been upgraded through on a real app using only
  `composer update` and the CHANGELOG, proving the boring-upgrade
  promise on live code rather than in theory.

Until then: a MINOR bump may break, a PATCH bump won't, and every
change of either kind is listed in
[`CHANGELOG.md`](../CHANGELOG.md).
