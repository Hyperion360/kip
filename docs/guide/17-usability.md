# 17. Usability: pages that answer questions

Most pages in a Kip app are for reading and deciding, not for operating. This
chapter is the usability contract: it binds every page this repository ships
(framework views, the skeleton, the examples, the docs) and is the bar
recommended for applications built on Kip, which may set their own. The rules
come in order: presentation first, inferred context second, interaction last.
They pair with two neighbors: the one-query budget in
[chapter 15](15-performance-contract.md) decides what data a page can afford,
and the JavaScript policy in [chapter 4](04-views.md) limits what scripts may
add.

## Start from the questions the page must answer

Before writing a template, write down the questions a visitor arrives with. A
story listing: what is new, what is it about, is it finished, how long is it.
An admin table: what changed, what needs attention, how do I act on it. The
page is judged by whether a reader answers those questions by looking. A page
that works as an index into other pages when its job is to support a
comparison is a defect, however clean the markup.

## Show the data; compare on one page

Every listing carries enough of each item to decide without opening it: the
title, the status, the date, the length, the two or three attributes that
drive the choice. Details sit one click away; the decision sits on the page.
Anything a reader might compare belongs on the same page, side by side:
comparison across pages taxes memory instead of sight, and the hand is slower
than the eye. The baseline is paper: a page that presents its data worse than
a well-set print table has no excuse, since it is rendered from the same data
every time. The one-query budget and this rule pull in the same direction: a
page that must earn all its data in a single query thinks twice about what
belongs on it.

## Arrange for the primary question

Placement is a decision, not a leftover. Group and order by what the reader is
comparing: newest first when recency is the question, grouped by status when
triage is the question, one organizing axis per page. Never arrange by the
shape of the database tables; the reader cannot see them and should not have
to know they exist.

## Lead the eye, then let it rest

Give the few things that matter the visual weight: strong titles, bold status,
clear headings. Set supporting detail small and quiet, so it disappears when
skimming and rewards a closer look. Keep the critical facts in one column the
eye can run down, inside the first eyespan. Semantic HTML does most of this
for free: heading levels, lists, tables, strong and em. Add classes only when
the semantics run out.

## Be exact only where the decision is exact

A price, a deadline, a filename, an email address: exact. Nearly everything
else reads better as context: "3 minutes ago" beside the full timestamp,
"about 200 pages", "2 of 12 chapters". Answer the question that must be exact
exactly, and answer the rest qualitatively. A wall of digits is clutter the
reader has to filter, and filtering is work the page was supposed to do.

## Infer context before asking for it

The server already knows things the reader should not have to re-enter. Now is
the prime landmark: relative times, today marked in a calendar, defaults that
mean soon (newest first, next unread chapter, drafts hidden from readers).
Last time is the second landmark: remember the reader's sort, filter, and
per-account choices, and start there; a returning reader lands where they left
off, not at zero. Ask only what cannot be inferred, and prefer correcting a
default to constructing an answer: prefill the form, and carry filters in the
URL so they survive a reload and can be shared.

## Interaction is a cost

Every click, form, and wizard step is time spent operating instead of
learning. Delete navigation that does not change what the reader sees. Prefer
one page that answers three questions to three pages that answer one each. A
link earns its place by leading to data the reader cannot see otherwise; a
button earns its place by performing a decision the page cannot make on the
reader's behalf. Confirmation pages that restate what just happened and demand
another click are pure overhead: after a write, redirect to the changed state
itself and confirm in one line that names what changed.

## When input is needed, use the data's own shape

Match the control to the thing being chosen. A handful of visible options
becomes links or chips the reader can take in at a glance; a status filter is
a row of the actual statuses, not a dropdown hiding five options behind a
click. Filter by the categories the content really has. A date is chosen as a
date. Generic, information-free controls are the last resort, after the page's
own vocabulary has been used up.

## Make state visible; correct in place

The page always displays its own current configuration: active filters as
removable chips, the draft or published badge on the content itself, the
chosen sort marked in the sort row, the active theme unmistakable. There are
no hidden modes. When the state is wrong, the reader fixes it where it shows:
toggle the chip, edit the field, flip the badge, instead of hunting for a
settings page. Every state change is visible in what the page shows, never
only in a transient message or a title.

## Prefer forms the reader already knows

Lists, tables, timelines, calendars, sentences. The old forms are understood
without instruction, survive every browser, and read well without scripting,
which is exactly what the enhancement-only policy assumes. Spend the
innovation budget on what the data shows, not on novel chrome around it.

## How the contract is checked

Render the page and ask the questions from the first section. If an answer
needs a click, a memory of another page, or arithmetic in the reader's head,
the page is not done. Design and accessibility review sit beside the test
suite in the QA battery ([chapter 16](16-building-with-ai.md)); a page can be
green in every test and still fail this chapter, and that failure is found by
looking, which is the point.
