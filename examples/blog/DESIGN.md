# DESIGN.md: the blog's shipped design

The visual reference for `examples/blog`, the tutorial's end-state app. The
source of truth is the mock `Kip admin panel design/Kip Admin.dc.html`
(screen set "Round 4 · the public site · examples/blog"); this file records
what shipped from it. The whole system lives in `public/style.css` and the
page markup in `app/views/`. No JavaScript, no webfonts, no images.

## Tokens

| Token | Light | Dark |
|---|---|---|
| `--bg` | `#f8f7f3` | `#141816` |
| `--fg` | `#1c2320` | `#e6ebe7` |
| `--muted` (secondary text) | `#4b544e` | `#b4bdb7` |
| `--faint` (meta, labels, footer) | `#5f6962` | `#939d96` |
| `--hairline` (list rules, cards, footer) | `#e4e2da` | `#2a312d` |
| `--border` (buttons, theme switch) | `#d6d3c9` | `#3a433e` |
| `--input-border` | `#cfccc2` | `#3a433e` |
| `--card` | `#fff` | `#1b201d` |
| `--rule` (strong structural rule) | `#1c2320` | `#b4bdb7` |
| `--accent` (primary button) | `#1f4f42` | `#2f6b5a` |
| `--accent-hover` | `#2a6a58` | `#3a7d6a` |
| `--link` | `#1f4f42` | `#8fc9b0` |
| `--focus-ring` | `#cfe3d9` | `#2f6b5a` |
| `--wash` (quiet hover) | `#efede6` | `#232a26` |
| `--danger-border` / `-bg` / `-fg` | `#e2b8ad` / `#f6e6e1` / `#9c2f1d` | `#6b3428` / `#3a211b` / `#f08a74` |
| `--switch-on-bg` / `-fg` (active theme button, filled Log in) | `#1c2320` / `#f8f7f3` | `#e6ebe7` / `#141816` |

Fonts: `--serif` (`ui-serif, "Iowan Old Style", Charter, "Bitstream
Charter", Georgia, serif`) for headings, the post body, and the editor
inputs; `--mono` (`ui-monospace, "SF Mono", Menlo, monospace`) for dates and
meta lines; the system sans stack for everything else.

## The five screens

| Screen | Route | Column width |
|---|---|---|
| Home | `/` | 776px |
| Posts index | `/posts` | 776px |
| Post | `/posts/show/N` | 776px |
| Editor (create and edit) | `/posts/create`, `/posts/edit/N` | 856px |
| Log in | `/auth/login` | 536px |

Print-like reading layout: square corners everywhere, serif display type,
hairline rules between list rows, and strong rules under the nav and each
page head. Desktop sizes assume a 1280px viewport; under 800px the paddings
tighten, the type steps down, and the editor's action bar stacks vertically.

## Theme mechanics

- Light values sit on `:root`; dark values on `:root[data-theme="dark"]`.
- With no explicit choice, dark also applies under
  `@media (prefers-color-scheme: dark) :root:not([data-theme="light"])`, so
  Auto follows the OS setting.
- The layout bakes the resolved choice into `data-theme` on `<html>`, so a
  picked theme renders server-side with no scripting.
- The choice is one cookie, `kip_theme`, sent with `Path=/` and shared with
  the admin panel: one preference across both surfaces. Auto deletes the
  cookie.
- `POST /theme` sets it (`theme` is `auto|light|dark`, anything else is
  `auto`) and redirects to the validated `back` target. The footer form
  posts to it on every page except the editor screens, where submitting the
  form would reload the page and discard an unsaved draft.
