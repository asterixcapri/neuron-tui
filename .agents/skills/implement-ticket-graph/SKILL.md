---
name: implement-ticket-graph
description: Read implementation-ticket dependency graphs produced by `to-tickets` and implement them in dependency order across any project-configured ticket system. Use when a multi-ticket effort has blocking edges and the host agent should compute each executable frontier, launch one fresh subagent per ticket, run independent tickets in parallel within the applicable parallelism directives, integrate successful commits serially, verify the combined state, and then release downstream tickets. Do not use for a single ticket or for Wayfinder decision maps.
---

# Implement Ticket Graph

Consume the ticket dependency graph written by `to-tickets`. Repeatedly select the
executable frontier, launch one fresh subagent per ticket, and hold dependent tickets
until their blockers integrate and pass verification.

Do not use this workflow for a Wayfinder map. Wayfinder tickets resolve decisions;
implementation tickets deliver code.

The issue tracker and triage label vocabulary should have been provided to you. If
`docs/agents/issue-tracker.md` is missing, tell the user to run
`/setup-matt-pocock-skills`. Take every tracker operation and label string from those
files: how to list, read, claim, and close a ticket, and how blocking edges are
represented. Where they leave a read undefined, infer it only if the result is
verifiable; never infer a write.

## Who owns what

The configured ticket system is the source of truth for ticket identity, state,
blocking edges, and comments. Each ticket belongs to exactly one subagent, which is
the only writer of that ticket's lifecycle: claim, acceptance criteria, failure, and
completion. This orchestrator only reads the tracker; it never writes a ticket, and
it never invents a status convention of its own.

## Implement the graph

### 1. Resolve policy

Take the effort reference and ticket scope from the invocation, and the integration
target, branch and worktree conventions, dependency restoration, and verification
commands from repository instructions.

Where nothing specifies otherwise, use these defaults: concurrency `2`, never above
the available subagent slots; retry limit `0`; automatic integration; and a
successful sibling integrates even when another subagent fails, provided it is
independently eligible.

### 2. Build the graph

List every implementation ticket in the effort and read enough of each to determine
identity, title, reference, execution state, and incoming blocking edges. Map the
tracker's own labels and states onto the scheduler's five: `eligible` may enter the
frontier, `claimed` is owned by someone else, `resolved` satisfies blockers,
`failed` awaits intervention, `ineligible` is open but not agent-executable. Write
the result as a snapshot:

```json
{
  "version": 1,
  "effort": { "id": "checkout-redesign", "reference": "#42" },
  "nodes": [
    { "id": "43", "reference": "#43", "title": "Create the checkout foundation",
      "state": "eligible", "blockedBy": [] },
    { "id": "44", "reference": "#44", "title": "Accept payment",
      "state": "eligible", "blockedBy": ["43"] }
  ]
}
```

Keep backend-specific fields under an optional `metadata` object and never use them
for graph computation. Then validate, resolving the path relative to this `SKILL.md`:

```bash
node <skill-path>/scripts/inspect-graph.mjs <snapshot.json> --rounds
```

The script is the authority on snapshot validity: do not launch subagents until it
passes. Stop and ask when the available reads cannot determine eligibility or blocker
satisfaction.

For a dry run, print the frontier and simulated rounds, the ticket-to-subagent
assignments and their packets, base commit, branch names, worktree paths, and the
verification and integration commands. Write nothing.

### 3. Launch one frontier

Select eligible tickets up to the concurrency cap, assign each once, then reread it
immediately before launch and proceed only while it is still eligible with satisfied
blockers. If another run claims it first, let its subagent report that and recompute
the frontier from a fresh read.

Resolve the exact base commit containing all previously integrated work. Give every
ticket a distinct branch and absolute worktree path from that same base, using Git
commands that refuse an existing branch or path. Restore dependencies inside each
worktree as the repository requires, and copy no secrets, ignored files, or
dirty-checkout contents unless repository instructions allow it.

Launch all selected subagents before waiting for any result, each with a fresh
context and this packet:

```text
Implement exactly this ticket: <exact title> (<tracker reference>; id: <exact id>).
Parent spec/effort: <reference>
Worktree: <absolute path>
Branch: <branch>
Base commit: <commit>
Verification: <project commands>
Completion report: outcome, commit, verification, dirty state, blockers

Claim the ticket before writing code.

Use /tdd where possible, at pre-agreed seams. Run typechecking regularly, single
test files regularly, and the full test suite once at the end. Once done, use
/code-review to review the work. Commit your work to the branch above.

Where a named skill is unavailable, do the same work without it: write the test
before the code, and review your own diff against the ticket and the repository's
coding standards before committing.

Record acceptance criteria and final ticket state from what you observed: report
failure rather than closing work that did not pass verification.

Reconstruct context from repository instructions, this ticket and its comments, the
parent spec, domain and architecture docs, and the relevant code. Touch no other
ticket.
```

Substitute the project's own names for `/tdd` and `/code-review` where they differ.
Never make launch depend on either being installed: the packet already states the
work to do without them.

### 4. Collect

Wait for every subagent in the frontier, preserving successful sibling results when
one fails. Each subagent is the authority on its own ticket, but accept its result
for integration only when it produced a commit on its assigned branch, passed the
required verification, reported the exact commit hash, and left no unexplained dirty
state. A stopped subagent is never a resolved ticket. Report any failure or
escalation it left behind, and update nothing yourself.

### 5. Integrate serially

Keep the main checkout single-writer. Invoking this skill authorizes integration, so
proceed without an approval checkpoint: integrate successful commits
one at a time in a deterministic order, running the fast gate after each, and report
failed siblings while continuing with the independently eligible ones.

Ask for intervention only when integration hits something the repository's documented
workflows cannot resolve safely — a merge conflict, an ambiguous target, a failed
gate, unexpected dirty state. Use the project's merge-conflict workflow where one
exists; do not guess between competing intent.

Once the selected commits are in, run the full verification suite against their
combined state and record locally which commits integrated and which gates passed.
Repair no ticket state here. If the tracker does not show the expected result, report
the discrepancy and keep downstream tickets blocked.

### 6. Recompute

Reread the tracker rather than mutating the old snapshot, then normalize, validate,
and compute the next frontier. A ticket executed in this run satisfies its downstream
edges only once the tracker reports its terminal state and its commit is integrated
with all required gates passing. Begin the next fan-out only after the previous
integration completes.

Stop when every scoped ticket is resolved or no executable frontier remains. For a
stalled graph, report each unresolved ticket, its state, and the blockers or
in-progress assignments preventing execution.
