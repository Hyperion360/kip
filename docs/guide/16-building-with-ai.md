# 16. Building with AI agents

Kip was built alongside AI coding agents, and its constraints are chosen so
agents succeed with it. This chapter shares a workflow that has carried a
real application from empty directory to shipped phases, so you can run it
with any capable coding assistant.

## Why agents do well with Kip

Zero JavaScript means zero toolchain. There is no bundler to configure
wrong, no build step to forget, no framework magic between the file an
agent edits and the behavior it tests. Every page is a PHP file that
renders HTML.

Zero runtime dependencies means the whole system fits in a context window:
the framework source is small, plain, and final classes all the way down.
Convention routing is predictable: /post/show/1 maps to
PostController::show('1'), and an agent can hold that rule forever.

SQLite plus migrations-as-plain-SQL makes the test loop fast. A test
database is a temp file; a migration is a `.sql` file with `-- up` and
`-- down` markers, or a PHP class with up() and down() when it needs logic;
resetting state costs milliseconds, so agents can afford to run the suite
constantly, which is exactly what you want them doing.

## The loop: plan, review, execute, gate

The single biggest lesson: never let the agent go straight from idea to
code. Use a four-stage loop and let each stage catch what the previous one
misses.

1. Plan first. The agent writes an implementation plan as bite-sized tasks
   with the actual code, actual commands, and expected outputs inline. A
   plan step that says "add error handling" is a defect; a plan step that
   shows the failing test, the run command, the expected failure text, the
   implementation, and the commit message is executable by anyone,
   including a different agent with no memory of the conversation.
2. Review the plan independently. A second pass with fresh context, asked
   to find what the planning pass missed, is worth more than any amount of
   self-checking. In one real development plan, eleven defects survived
   both the author and the self-review, and the independent pass caught
   every one: a column renamed in one document but not another, a
   fallback made unreachable by its own template, broken test arithmetic.
   Two readers agreeing is signal; one reader checking their own work is
   not.
3. Execute task by task with gates. Dispatch one task at a time (a fresh
   agent per task if you can), verify the reported outputs against the
   plan's expected outputs, and review between tasks. Give executors an
   explicit stop rule: when reality disagrees with the plan, stop and
   report, do not improvise. In the same development, executor gates
   caught wrong column names, a missing fixture row, and a method
   scheduled into the wrong task, each fixed in minutes because the
   executor stopped instead of pushing through.
4. Gate with automated QA. After each milestone, run a full quality
   battery over the result: tests, coverage, static analysis, security
   checks, accessibility if there is UI, a database query audit, and a
   live HTTP walkthrough. Expect the gate to find real things after a
   green suite (one real gate caught a pagination overflow that turned
   huge page numbers into 500s, and a color contrast failure in the dark
   theme) and let fixes flow back as new commits with their own tests.

## Keep the agent on a short leash of rules

Write your constraints down where the agent reads them every session: a
project instructions file (AGENTS.md is the common convention) stating the
stack, the commands, the port rules, the commit identity, the writing
style, and the hard invariants (zero JavaScript, the performance contract
from [chapter 15](15-performance-contract.md), no dependencies). Rules in a
file the agent re-reads beat rules you repeat in chat.

Track phases in a file the agent updates: a table of phases, plan
documents, commits, and QA verdicts. Agents drift less when the state of
the world is written down, and you can resume any phase months later from
the tracker alone.

## Building many features at once with agents

The loop above runs one feature at a time, and it scales to several agents
in parallel when the features are independent. The arrangement that keeps
parallel agents out of each other's way:

1. **One worktree per feature.** `git worktree add` gives each agent its
   own checkout of the same repository on its own branch, so two agents
   never edit the same working tree. The full gate (the whole suite,
   static analysis, whatever you gate merges on) runs inside that worktree
   before the agent reports done; a branch that has not passed the gate in
   its own worktree is not finished.
2. **Feature folders keep the trees disjoint.** A feature's controllers,
   views, and migrations live under `app/Features/<Name>/` (see
   [Feature folders in chapter 3](03-controllers.md#feature-folders)), so
   parallel branches touch disjoint trees and merges stop being text
   fights.
3. **Own your data.** Each feature's migrations create the feature's own
   tables and columns; a feature never alters a table another feature
   owns. Two agents reworking the same table in parallel is the merge
   conflict no reviewer can resolve, so the rule is absolute.
4. **A review gate between merges.** Branches merge one at a time, with a
   human review (plus the gate) between every merge, never two branches
   landing at once.

One migration rule has teeth when you revert a branch: reverting the
branch removes its migration files, and rollback needs the files present
to know what to undo. The drill, in order: run `php bin/kip rollback` for
the feature's batch first, while its migration files are still checked
out, then revert the branch. It works because batches stay per-feature:
run `php bin/kip migrate` after every merge, before the next branch lands,
and each feature's migrations are exactly one batch in the ledger. When
several features landed after the one being reverted, roll their batches
back first; rollback only ever undoes the most recent batch (see
[chapter 5](05-database-and-migrations.md)).

## Adding AI features to your Kip app

Everything above uses agents to build your app. The other direction is your
app calling a model at request time: classification, extraction, drafting,
summarizing. Kip takes no dependency for this and ships no battery for it,
because the moving parts are one HTTP call and one loop, and every provider
speaks a documented JSON API. What follows is the shape that holds up in
production, and the discipline that separates a feature from a demo. The
samples are app-side sketches: they omit wiring (config, repositories,
templates) to stay readable, and they are not part of
[`examples/blog`](../../examples/blog).

**Buy the key, not the framework.** Pick a provider, put its base URL, API
key, and model name in `config.php`, and keep your code provider-shaped, not
provider-coupled: one small client class is the whole abstraction. Switching
inside the OpenAI-compatible chat-completions protocol, which most providers
and local gateways speak, is a config change; a provider with a different
protocol needs a small adapter, so decide that once, up front. Bring your
own keys per tenant if you serve many: store the key in your own tables,
resolve it at call time, and the platform never pays a metered bill for a
tenant's usage.

**The transport is the same one Kip's own backup battery uses.** curl when
the extension is loaded, PHP stream contexts when it is not; see
`src/S3.php` for the feature-detection pattern. Two rules it already
encodes, and the client below keeps: never follow a redirect, because the
next hop receives the `Authorization` header, and inspect the HTTP status
instead of trusting the body. One shared-host caveat: a host that disables
`allow_url_fopen` has no streams lane at all, so detect all three
conditions up front the way `src/S3.php` does and fail with a clear message
instead of a confusing warning. A minimal client (one asymmetry to know:
curl's timeout bounds the whole transfer, while the streams timeout bounds
a single read, so a drip-feeding endpoint is fully bounded only in the curl
lane; and the https-only guard that keeps the key off plain HTTP also means
fixture-testing the client locally takes a TLS-enabled stub server rather
than a plain-HTTP one):

```php
// App\Ai\LlmClient, PSR-4 under app/src/Ai/
namespace App\Ai;

final class LlmClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
    ) {}

    /** @param list<array{role: string, content: string}> $messages */
    public function complete(array $messages, int $timeout = 30, int $maxTokens = 1024): string
    {
        if (!str_starts_with($this->baseUrl, 'https://')) {
            throw new \RuntimeException('ai base URL must be https: the key travels in a header');
        }
        if (!extension_loaded('curl')
            && (!extension_loaded('openssl') || (int) ini_get('allow_url_fopen') !== 1)) {
            throw new \RuntimeException('no ai transport: need ext-curl, or openssl plus allow_url_fopen');
        }
        $body = json_encode([
            'model'      => $this->model,
            'messages'   => $messages,
            'max_tokens' => $maxTokens,
        ], JSON_THROW_ON_ERROR);

        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        if (extension_loaded('curl')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, // curl's default, pinned on purpose
            ]);
            $raw  = curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($raw === false) {
                throw new \RuntimeException('ai request failed: ' . curl_error($handle));
            }
        } else {
            $context = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true, // a 4xx/5xx still carries a status to inspect
                // Never follow a redirect: the wrapper re-sends these
                // headers, Authorization included, to the redirect target.
                'follow_location' => 0,
                'max_redirects'   => 0,
            ]]);
            $raw  = @file_get_contents($url, false, $context);
            $code = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                    $code = (int) $m[1]; // keep the last status line seen
                }
            }
            if ($raw === false && $code === 0) {
                throw new \RuntimeException('ai request failed: no HTTP response');
            }
        }

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("ai request failed: HTTP {$code}");
        }
        $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        return $data['choices'][0]['message']['content']
            ?? throw new \RuntimeException('ai response shape not recognized');
    }
}
```

**Force structured output and validate it.** For anything a program acts on
(classification, extraction, routing), demand JSON with an exact shape,
validate against that shape in PHP, retry once with the validation error
appended to the conversation, then fall back to a human queue. Never trust
the shape; models drift:

```php
$schema = 'Reply with JSON only: {"category": "billing"|"bug"|"question", "urgency": "low"|"high"}';
$attempt = $client->complete([
    ['role' => 'system', 'content' => $schema],
    ['role' => 'user', 'content' => $text],
]);
$data = json_decode($attempt, true);
$valid = fn ($d) => is_array($d)
    && in_array($d['category'] ?? null, ['billing', 'bug', 'question'], true)
    && in_array($d['urgency'] ?? null, ['low', 'high'], true);

if (!$valid($data)) {
    $attempt = $client->complete([
        ['role' => 'system', 'content' => $schema],
        ['role' => 'user', 'content' => $text],
        ['role' => 'assistant', 'content' => $attempt],
        ['role' => 'user', 'content' => 'That was not valid JSON per the schema. Reply with JSON only.'],
    ]);
    $data = json_decode($attempt, true);
}
if (!$valid($data)) {
    // fall back: leave the item for human triage, log the raw output
}
```

**Tools need an allowlist and a short leash.** A tool is a named PHP callable
the model may request by name; the loop executes only what the allowlist
contains, at most a handful of steps. Real providers ship native tool-call
APIs; the sample uses a plain-text TOOL_CALL convention on purpose, so it
stays provider-neutral. Two rules with teeth: tools that write
go through the same validated actions a human would use (never let the model
compose SQL against the content database; if you must query with it, do it
read-only over allowlisted views), and anything expensive to reverse lands
in a review queue first, not in production state. Kip's admin panel
([chapter 12](12-admin-panel.md)) is a ready-made review surface. A third
rule the sample bakes in: a tool takes ONE array argument and validates its
own named fields, because binding positional arguments from JSON key order
silently swaps values when the model reorders keys. And the authority rule:
tools run with your app's credentials, not the model's, so scope every tool
to the authenticated user or tenant and let it read only what that user
could have read themselves; the provider sees every byte a tool returns. And
assume attacker-controlled input end to end: a user message can carry a
TOOL_CALL line, and the model can parrot one from quoted text, so treat tool
arguments as attacker input and let the allowlist, the step cap, and the
approval queue be the boundary.

```php
$tenantId = $session->get('tenant_id'); // the signed-in operator's tenant
$tools = [
    'find_customer' => function (array $in) use ($repo, $tenantId): string {
        // reads only what the signed-in operator may read; the repo scopes it
        return json_encode($repo->forTenant($tenantId)->findByEmail((string) ($in['email'] ?? '')) ?? 'not found');
    },
    'draft_refund' => function (array $in) use ($queue): string {
        $cents = (int) ($in['cents'] ?? 0);
        if ($cents <= 0 || $cents > 1_000_000) {
            return 'refused: cents must be between 1 and 1000000'; // a negative refund is a charge
        }
        $queue->draft((string) ($in['orderId'] ?? ''), $cents); // a row, not a refund
        return 'drafted, awaiting approval';
    },
];
$messages = [
    ['role' => 'system', 'content' => $instructions
        . ' Tools: find_customer {"email": string}, draft_refund {"orderId": string, "cents": int}.'
        . ' To call one, reply with exactly one line "TOOL_CALL: name json-args".'],
    ['role' => 'user', 'content' => $text],
];

for ($step = 0; $step < 4; $step++) {
    $reply = $client->complete($messages);
    if (!preg_match('/^TOOL_CALL: (\S+) (.+)$/m', $reply, $m)) { break; } // model is done
    $messages[] = ['role' => 'assistant', 'content' => $reply];
    $args    = json_decode($m[2], true);
    $args    = is_array($args) ? $args : [];
    $handler = $tools[$m[1]] ?? null;
    try {
        $result = $handler === null ? 'unknown tool' : $handler($args); // named fields, never arg order
    } catch (Throwable $e) {
        $result = 'tool failed: ' . $e->getMessage(); // feed it back; the loop and the transcript survive
    }
    $messages[] = ['role' => 'user', 'content' => "tool {$m[1]} returned: " . json_encode($result)];
}
```

**Serve it server-rendered.** A model call is a slow method call with extra
reads around it (tenant key lookup, tool queries), so keep it off the
normal page-render path: trigger it from a POST action or a queued job,
write a pending row, and render the result when it is ready, as plain HTML.
No streaming, no JavaScript, and the zero-JS contract holds. The rate
limiter counts only non-GET requests to a configured prefix (`Kip\RateLimit`,
[chapter 6](06-security.md)), which is one more reason the trigger is a
POST. Set `max_tokens` and a timeout on every call, and cache results that
only depend on their input.

**Keep AI traffic out of the content database.** Model inputs, outputs, and
latency are observability data: log them the way the audit log does, in
their own SQLite file ([chapter 9](09-audit-log.md)), so the one-query page
budget ([chapter 15](15-performance-contract.md)) is never in the blast
radius of a debug session.

**Evaluate model changes like code changes.** A prompt edit is a production
deploy. Keep a golden set of inputs with expected outcomes (twenty real
examples beat zero), run it on every prompt or model change, and gate the
change on the pass rate the way you gate on the test suite. Log enough to
debug failures: the raw prompt, the raw output, and the step counts. Cap
any self-correction loop at one or two rounds; beyond that you are paying
latency and tokens for noise.

## What to automate vs. what to read yourself

Let agents own: the plan drafting, the test writing, the mechanical
refactors, the QA batteries, the boring parts of migrations. Read yourself:
the schema (data outlives code), the security-relevant seams (auth,
uploads, anything rendering stored HTML), and every plan review before
execution. The workflow's whole point is that the machine writes most of
the code while the human owns the decisions that are expensive to reverse.
