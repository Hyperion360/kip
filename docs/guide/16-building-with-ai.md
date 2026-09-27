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

## What to automate vs. what to read yourself

Let agents own: the plan drafting, the test writing, the mechanical
refactors, the QA batteries, the boring parts of migrations. Read yourself:
the schema (data outlives code), the security-relevant seams (auth,
uploads, anything rendering stored HTML), and every plan review before
execution. The workflow's whole point is that the machine writes most of
the code while the human owns the decisions that are expensive to reverse.
