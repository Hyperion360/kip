# 15. The performance contract

Kip applications work best under a simple budget: every rendered page
executes at most one query against the content database. This chapter
explains the budget, the SQL patterns that keep you inside it, and how to
enforce it in tests so it never silently erodes. Examples use the
`examples/blog` schema (posts, comments, users) rather than a generic one.

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

## Patterns that keep you at one query

LEFT JOIN for optional relations. A post page that wants the author's name
even when the users row is missing uses LEFT JOIN, never a follow-up lookup.

GROUP_CONCAT to fold children into the parent row. A post's comment list is
one correlated subquery returning "id|name|body" chunks, parsed in PHP:

```php
$sql = 'SELECT p.*, u.name AS author_name,
        (SELECT GROUP_CONCAT(c.id || "|" || c.name || "|" || c.body, '~')
         FROM comments c WHERE c.post_id = p.id) AS comments_blob
        FROM posts p LEFT JOIN users u ON u.id = p.user_id
        WHERE p.id = ?';
```

(Parse and sort in PHP rather than trusting aggregate ordering: explode on
your separator, cast, ksort.)

Conditional aggregation to pivot one child out of the set. Fetch a post and
its newest comment in one query:

```php
$sql = 'SELECT p.*, u.name AS author_name,
        MAX(c.created_at) AS newest_comment_at,
        MAX(CASE WHEN c.created_at = (SELECT MAX(c2.created_at) FROM comments c2
                                      WHERE c2.post_id = p.id) THEN c.body END) AS newest_comment_body
        FROM posts p
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN comments c ON c.post_id = p.id
        WHERE p.id = ?
        GROUP BY p.id';
```

A NULL pivot column means the child does not exist; that is your empty
state.

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
index, and you prove it with EXPLAIN QUERY PLAN:

```sql
EXPLAIN QUERY PLAN
SELECT id FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT 20
```

Two words fail the contract: SCAN (a full table scan on a page-serving
query) and TEMP B-TREE (an in-memory sort because no index matches the
ordering). The listing above wants an index like posts(user_id, created_at
DESC).

## Enforce it in tests

A budget that is not tested is a suggestion. `Kip\Database` exposes
`onQuery`, a per-connection tap, so a test can count the real queries on
the real connection behind the application:

```php
$app = new Kip\App($config);            // $config WITHOUT cache_db (see below)
$db = $app->container->make(Kip\Database::class);
$queries = 0;
$db->onQuery(function () use (&$queries): void { $queries++; });
$res = $app->handle(new Kip\Http\Request('GET', '/post/1', [], [], []));
$db->onQuery(fn () => null);
assert($res->status === 200);
assert($queries <= 1);
```

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
