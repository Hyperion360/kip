# 15. The performance contract

Kip applications work best under a simple budget: every rendered page
executes at most one query against the content database. This chapter
explains the budget, the SQL patterns that keep you inside it, and how to
enforce it in tests so it never silently erodes. Examples use the
`examples/blog` schema (posts, comments, users) rather than a generic one,
and every one of them runs against it.

## The budget

A page render may run ONE query against the content database. Anything the
page needs from a second table arrives through that same query, assembled
with joins and aggregation rather than a second round trip. Two things sit
outside the budget by design: the request audit write, which goes to the
separate logs database (see [chapter 9](09-audit-log.md)), and session
validation on pages that require login.

The budget is not about SQLite being slow; simple queries run in
microseconds. The budget exists because every extra query is a place where
N+1 patterns, missing indexes, and accidental work hide, and because the
cache-miss path deserves the same discipline as the cached hit.

The bundled `examples/blog` predates this chapter and does not yet meet it in
two places. Its `PostsController::show()` runs two queries, one for the post
and one for its comments, because the tutorial adds comments as a separate
step; the first pattern below folds them into one. Its post listing sorts
without an index; the index section below shows the plan and the fix.

## Patterns that keep you at one query

`json_group_array` to fold children into the parent row. A post's comments
arrive as one JSON array in the post's own row:

```php
$sql = "SELECT p.*,
        (SELECT json_group_array(json_object('id', c.id, 'author', c.author,
                                             'body', c.body, 'created_at', c.created_at))
           FROM comments c WHERE c.post_id = p.id) AS comments_json
        FROM posts p
        WHERE p.id = ?";
$post = $db->one($sql, [$id]);
if ($post === null) return new Kip\Http\Response('Post not found', 404);
$comments = json_decode($post['comments_json'], true);
usort($comments, fn ($a, $b) => [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']]);
```

Sort in PHP rather than trusting aggregate order. A post with no comments
yields an empty array, not NULL. Prefer this to `GROUP_CONCAT` with a
separator: any separator you choose can appear in text a user typed, and one
comment containing it splits into extra fields and corrupts the rows after
it. JSON encoding has no such character.

LEFT JOIN for optional relations. A post header that shows the comment
count, including zero, uses LEFT JOIN with COUNT rather than a second
count query:

```php
$sql = "SELECT p.id, p.title, COUNT(c.id) AS comment_count
        FROM posts p
        LEFT JOIN comments c ON c.post_id = p.id
        WHERE p.id = ?
        GROUP BY p.id";
```

The plan is two SEARCH steps: the post by primary key, then its comments
through the `comments(post_id, created_at, id)` index.

Count `c.id`, not `*`: `COUNT(*)` counts the NULL row a LEFT JOIN produces
for a post with no comments, and reports 1 instead of 0.

Conditional aggregation to pivot one child out of the set. Fetch a post and
its newest comment in one query:

```php
$sql = "SELECT p.*,
        MAX(c.created_at) AS newest_comment_at,
        MAX(CASE WHEN c.id = (SELECT c2.id FROM comments c2
                              WHERE c2.post_id = p.id
                              ORDER BY c2.created_at DESC, c2.id DESC
                              LIMIT 1) THEN c.body END) AS newest_comment_body
        FROM posts p
        LEFT JOIN comments c ON c.post_id = p.id
        WHERE p.id = ?
        GROUP BY p.id";
```

Match on `id`, not on the timestamp. Two comments with the same
`created_at` would both match a timestamp test, and `MAX(...)` would then
return whichever body sorts last as text rather than the newest comment. A
NULL pivot column means the child does not exist; that is your empty state.

UNION ALL when shapes differ. Two result shapes in one round trip become
one query with a discriminator column and NULLs where the shapes disagree.

## Writes: one transaction per action

Batch every write an action performs inside one transaction
(`$db->begin()` / `$db->commit()`, `$db->rollBack()` on throw). Per-row
autocommit pays a sync per row; a transaction pays one. Prefer
INSERT ... ON CONFLICT for upserts over check-then-insert, which is two
queries and a race.

## Indexes must match the query plans

Every WHERE and ORDER BY a page-serving query uses should be backed by an
index, and you prove it with EXPLAIN QUERY PLAN. The blog's comment list:

```sql
EXPLAIN QUERY PLAN
SELECT * FROM comments WHERE post_id = ? ORDER BY created_at, id
```

Two plan lines fail the contract: a bare `SCAN table` (a full table scan)
and `USE TEMP B-TREE` (a sort because no index matches the ordering). A
`SCAN table USING INDEX` bounded by `LIMIT` passes: it walks the index in
order and stops after one page, which is how pagination is supposed to
read. The blog's `comments(post_id, created_at, id)` index covers both the
filter and the ordering, so the plan is a single SEARCH with no sort. Drop
the trailing columns from that index and the same query gains a
TEMP B-TREE.

The blog's post listing is the counterexample:

```sql
EXPLAIN QUERY PLAN
SELECT * FROM posts ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?
```

Today that plans as `SCAN posts` plus `USE TEMP B-TREE FOR ORDER BY`: every
post is read and sorted to return one page of 20. A migration adding
`CREATE INDEX idx_posts_created_at ON posts (created_at)` changes the plan
to `SCAN posts USING INDEX idx_posts_created_at`. The `id` tie-break needs
no index column of its own, because SQLite appends the rowid to every
index entry.

## Enforce it in tests

A budget that is not tested is a suggestion. `Kip\Database` exposes
`onQuery`, a per-connection tap, so a test can count the real queries on
the real connection behind the application. Inside a PHPUnit test:

```php
$app = new Kip\App($config);            // $config WITHOUT cache_db (see below)
$db = $app->container->make(Kip\Database::class);
$queries = 0;
$db->onQuery(function () use (&$queries): void { $queries++; });
$res = $app->handle(new Kip\Http\Request('GET', '/posts/show/1', [], [], []));
$db->onQuery(fn () => null);

$this->assertSame(200, $res->status);
$this->assertLessThanOrEqual(1, $queries);
```

Assert the status first. A mistyped route returns 404 without touching the
database, so zero queries would satisfy the budget and the test would pass
while measuring nothing. Use PHPUnit's assertions rather than `assert()`,
which production PHP disables.

Two caveats from the framework's internals. First, the tap is a single
slot: when `cache_db` is configured, the page cache installs its own
query tagger for the duration of the request and yours is detached (see
[chapter 7](07-performance.md)), so budget tests configure the app without
`cache_db`. Second, the tap counts every query on that connection,
including the framework's session-validation lookup on pages behind
`#[Auth]`; budget-test guest pages, or account for that one query
explicitly.

Run one budget test per page shape and the budget holds forever: a refactor
that adds a second query fails the suite the moment it lands.
