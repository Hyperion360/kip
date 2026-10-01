# DESIGN.md — My Blog (the Kip example app)

Instructions for styling the blog's public pages. Written for a designer or a
design agent working without further context. The admin panel is already done
and is the source of truth: read `src/Admin/views/layout.php` (the whole design
system lives in its inline stylesheet) before writing a line.

## The system, extracted

- **Voice:** quiet, warm, print-like. Content first. Nothing decorative that
  does not carry information. Zero JavaScript, zero webfonts, zero images
  required.
- **Corners:** square everywhere. `border-radius: 0`. No shadows except the
  admin's centered confirm card.
- **Type:** display/headings `ui-serif,'Iowan Old Style',Charter,'Bitstream
  Charter',Georgia,serif` weight 400 (h1 44px desktop / 32px under 800px,
  letter-spacing -0.01em). Body `system-ui,-apple-system,'Segoe UI',sans-serif`
  16px/1.6. Data, dates, and metadata `ui-monospace,'SF Mono',Menlo,monospace`
  13px. Labels 11-12px uppercase, letter-spacing .06-.1em, muted color.
- **Light palette:** page `#f8f7f3`, panel `#fff`, raised side `#f1efe8`, lines
  `#e4e2da` / `#eeece6`, text `#1c2320`, body `#4b544e`, faint `#5f6962`,
  accent `#1f4f42` (hover `#2a6a58`), accent surface `#e3ece6`, focus ring
  `#cfe3d9`, danger `#9c2f1d` (wash `#f6e6e1`), warn chip `#f7ecd4/#7a4d00`,
  neutral chip `#efede6/#4b544e`, input line `#cfccc2`, table head `#faf9f6`.
- **Dark palette (same names):** `#141816` page, `#1b201d` panel, `#101311`
  side, lines `#2a312d`/`#232a26`, text `#e6ebe7`, body `#b4bdb7`, faint
  `#939d96`, accent text `#8fc9b0`, buttons `#2f6b5a` (hover `#3a7d6a`), accent
  surface `#1d3a31`, ring `#2f6b5a`, danger text `#f08a74` (button `#b23b26`),
  warn `#3a2e14/#e8c270`, chip `#242b27/#b4bdb7`, input line `#3a433e`, head
  `#1f2522`.
- **Theme mechanics (copy, do not invent):** light values on `:root`; dark
  under `:root[data-theme="dark"]` and under
  `@media (prefers-color-scheme: dark) :root:not([data-theme="light"])`;
  `color-scheme` flips with each. The blog stays Auto-only (no theme buttons
  in public nav); the admin's `kip_theme` cookie is scoped to `/admin` and
  must not leak here.
- **States:** `:focus-visible` 2px solid accent, offset 2; inputs on focus
  swap border to accent + `box-shadow: 0 0 0 3px` ring; hover washes use the
  side/wash surfaces; `::selection` accent surface. Touch targets 44px
  minimum. Inputs 16px on mobile (iOS zoom).

## Per-page instructions

Replace the five generic tokens in `public/style.css` with the full palettes
above, then style page by page. Every page keeps exactly one `<h1>`, real
`<nav>`/`<main>` landmarks, and zero inline event handlers.

1. **Layout + top nav** (`app/views/layout.php`). Plain header bar, bottom
   hairline, not sticky. Wordmark "My Blog" in the serif, italic, 26px, text
   color, no underline. Nav links 15px, body color, 8px 10px padding, hover
   wash. The Admin link and Log out button (it is a real `<form><button>`,
   style it as a borderless nav link with `cursor:pointer`) align right:
   `header nav { display:flex; align-items:center; gap:4px }` with
   `margin-left:auto` on the session items. No Admin/Log-out when logged out:
   just the Log in link.
2. **Home** (`home/index.php`). Currently bare. Give it the admin home's
   structure without its density: serif h1 ("A quiet blog about shipping
   software" or the owner's real line), one lede paragraph (max-width 560px,
   body color), and one solid accent button to `/posts`. Nothing else. No
   hero image, no three-column features, no stats row.
3. **Posts index** (`posts/index.php`). List rows, not cards: each post is a
   serif 20px title link over a 13px mono meta line
   (`created_at · n comments`), separated by `--kip-line-soft` hairlines.
   Titles truncate with ellipsis. Render the pager only when there is
   something to page: reuse the admin pager exactly (44px bordered
   Newer/Older, disabled end as text with `aria-disabled`), and delete the
   always-printed empty `<nav>`. Empty state (no posts): one warm line and a
   Write button, admin-empty-state style.
4. **Post show** (`posts/show.php`). Article: max-width 62ch, h1 serif 36-44px,
   meta line mono 13px faint, body 17px/1.7 body color. Long form: `p` spacing
   1em, no first-line indent. The comment list becomes bordered panels (admin
   card treatment: panel background, 1px line, author strong, timestamp mono
   faint). The comment form mirrors the admin form exactly: wrapping `<label>`
   per field, 11px 14px inputs with the focus ring, required marks in danger,
   one solid accent submit and nothing else. Errors land near the field.
5. **Write / Edit** (`posts/edit.php`). Same skeleton as the admin row form:
   one bordered panel (max-width 44rem), field stack gap 22px, Cancel link
   beside the submit. Under 800px the panel goes borderless and the action bar
   may stick to the viewport bottom like the admin's. Keep `required` and the
   CSRF hidden input exactly as they are.
6. **Log in** (`auth/login.php`). The admin confirm card: centered, max-width
   540px, panel background, hairline border, 40px padding. "Wrong email or
   password" renders as a danger-text line above the submit, never a browser
   alert. The 429 throttled message is a warn chip line.
7. **Errors (404/500)** and every empty state: one serif line saying what is
   true, one sentence of body copy, one obvious action link. Same layout
   skeleton as the admin's `.kip-empty`, centered, faint color, generous
   padding.

## Rules that are not negotiable

- No JavaScript. The `<script type="speculationrules">` and view transition
  already in the layout are the only script tags allowed.
- No new colors, radii, shadows, or fonts beyond the palettes above. If a
  state seems to need one, it needs a different treatment instead.
- Contrast: every text/background pair at least 4.5:1 (3:1 for 18px+).
  Placeholders and disabled controls are the only exemptions.
- Do not import the admin sidebar, its cards grid, or its chips into public
  pages. The blog is an editorial surface; the admin is a utility. They share
  tokens and type, not components.
- The AI-slop list applies with force: no purple, no gradients, no icon
  circles, no card-in-card, no emoji as design, no "Welcome to" copy.

## Acceptance checklist

- [ ] Guest sees: My Blog / Posts / Write / Log in. Admin sees: + Admin + Log
      out (form). Plain user: + Log out, no Admin.
- [ ] Light and dark both AA; toggle via OS setting only.
- [ ] Every page: one h1, landmarks intact, keyboard focus visible, 44px
      targets, no console errors, no horizontal scroll at 390px.
- [ ] Pages render correctly with cookies absent (guest, cacheable) — the
      cache-then-invalidate rule applies to any deploy: `kip cache:clear`.
- [ ] A post title with `<script>` in it renders as text everywhere it appears.
