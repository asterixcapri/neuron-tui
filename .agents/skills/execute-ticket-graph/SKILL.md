---
name: execute-ticket-graph
description: Read implementation-ticket dependency graphs produced by `to-tickets` and execute them in dependency order across any project-configured ticket system. Use when a multi-ticket effort has blocking edges and the host agent should compute each executable frontier, launch one fresh subagent per ticket with an explicit `implement` invocation, let `implement` own the ticket lifecycle, run independent tickets in parallel within the applicable parallelism directives, integrate successful commits serially, verify the combined state, and then release downstream tickets. Do not use for a single ticket or for Wayfinder decision maps.
---

# Execute Ticket Graph

Consume the ticket dependency graph written by `to-tickets`. Repeatedly select the
executable frontier, launch one fresh subagent per ticket, and require each subagent
to invoke the project's `implement` skill or equivalent. Run independent tickets in
parallel and hold dependent tickets until their blockers integrate and pass
verification.

## Preserve the seams

- Treat the configured ticket system as the source of truth for ticket identity,
  state, blocking edges, and comments.
- Keep every ticket-lifecycle mutation behind `implement`: claim, acceptance-criteria
  updates, failure handling, and completion. This orchestrator reads those states but
  never writes or redefines them.
- Read `docs/agents/issue-tracker.md` and repository instructions before querying
  tickets. If the tracker contract is incomplete, read
  [references/tracker-contract.md](references/tracker-contract.md) and settle only
  the missing read operations before continuing.
- Never require local Markdown when the project uses GitHub, GitLab, Linear, Jira,
  or another system.
- Normalize tracker data before graph computation. Resolve resource paths relative to
  this `SKILL.md`, then run
  `node <skill-path>/scripts/inspect-graph.mjs <snapshot.json> --rounds` against the
  canonical shape in [references/tracker-contract.md](references/tracker-contract.md).
- Delegate exactly one ticket to each subagent. Put the explicit `implement`
  invocation, including the exact ticket title and reference, in the first line of
  the subagent task. A prose instruction to invoke it later is not an invocation.
- Do not use this workflow to execute a Wayfinder map. Wayfinder tickets resolve
  decisions; implementation tickets deliver code.

## Execute the graph

### 1. Establish policy

Resolve these values from repository instructions, tracker documentation, or the
user's invocation:

- effort reference and ticket scope;
- maximum concurrency from the applicable user, repository, and host-agent
  parallelism directives, defaulting to `2` when none is specified and never
  exceeding the available subagent slots;
- eligible, in-progress, failed, and blocker-satisfied meanings for read-only
  scheduling;
- integration target, worktree parent directory, and branch naming convention;
- dependency restoration needed in a fresh worktree;
- verification required from each subagent before collection;
- fast verification after each integration and full verification after fan-in;
- integration mode, defaulting to automatic;
- retry limit, defaulting to `0`;
- whether a successful sibling may integrate when another subagent fails, defaulting
  to yes when the sibling is independently eligible.

Stop before fan-out only when the available reads cannot determine eligibility or
blocker satisfaction. Do not invent a state-changing tracker operation.

### 2. Discover and normalize

Use the tracker contract to list every implementation ticket in the supplied effort.
Fetch enough data to determine identity, title, reference, execution state, and all
incoming blocking edges. Map the results to a canonical snapshot without changing
the tracker.

Validate the snapshot with `scripts/inspect-graph.mjs`. Do not launch subagents if an
identity is duplicated, a blocker is missing, an edge is malformed, or the graph is
cyclic.

For a dry run, print:

- the current frontier and simulated rounds;
- ticket-to-subagent assignments;
- the exact `$implement` invocation for each assignment;
- base commit, branch names, and worktree paths;
- verification and integration commands.

Perform no writes during a dry run.

### 3. Select one frontier

Select eligible frontier tickets up to the concurrency cap. Assign every selected
ticket once in the run-local schedule, then reread it immediately before launch.
Launch it only while it remains eligible and its blockers remain satisfied.

Make no tracker write here. The explicitly invoked `implement` workflow owns the
claim and every later lifecycle transition. If another run takes the ticket between
selection and launch, let `implement` detect and report that outcome, then reread the
tracker before recomputing the frontier.

### 4. Isolate writes

Resolve the exact base commit containing all previously integrated work. Give every
ticket a distinct branch and absolute worktree path from that same base. Use safe
Git commands that reject existing branches and worktree paths.

Restore dependencies inside each worktree as the repository requires. Copy no
secrets, ignored files, or dirty-checkout contents unless repository instructions
explicitly allow them.

### 5. Fan out to `implement`

Launch all selected subagents before waiting for any result. Start every subagent
with a fresh context. Resolve the project's exact skill name first, then make the
invocation itself the first line of this packet. If no `implement` skill or
equivalent is available, stop before launch and ask the user to install the standard
skill with `npx skills@latest add mattpocock/skills --skill implement`. For the
standard skill, render the first line exactly as shown:

```text
Use $implement to implement exactly this ticket: <exact title> (<tracker reference>; id: <exact id>).
Parent spec/effort: <reference>
Worktree: <absolute path>
Branch: <branch>
Base commit: <commit>
Verification: <project commands>
Completion report: outcome, commit, verification, dirty state, blockers
```

Tell each subagent to reconstruct context from repository instructions, the assigned
ticket and comments, its parent spec, domain and architecture docs, and relevant
code. Tell it not to select or update any other ticket. Let `implement` own its
ticket-level completion rules, including stories and acceptance criteria; do not
restate or replace them in this orchestration skill.

### 6. Collect

Wait for every subagent in the frontier. Preserve successful sibling results when one
subagent fails. Treat the invoked `implement` workflow as the authority on whether its
ticket-level work is complete. Accept its result for integration only when the
orchestration envelope also confirms that it:

- completed the explicitly invoked `implement` workflow for its assigned ticket;
- produced a commit on its assigned branch;
- passed the required subagent verification;
- left no unexplained dirty state;
- reported the exact commit hash.

Never translate a stopped subagent into a resolved ticket. Make no lifecycle update;
reread the result written by `implement` and report any failure or escalation it
left behind.

### 7. Fan in serially

Keep the main checkout single-writer. Treat the graph-execution invocation as
authorization to integrate eligible commits: proceed without an approval checkpoint.
Integrate successful commits one at a time in a deterministic order, running the fast
gate after each integration. Report failed siblings while continuing to integrate
independently eligible successful siblings.

Ask for user intervention only when integration encounters a problem that the
repository's documented workflows cannot resolve safely, such as a merge conflict,
an ambiguous target, a failed gate, or unexpected dirty state. Invoke the project's
explicit merge-conflict workflow when available; do not guess between competing
intent.

After all selected commits integrate, run the full verification suite against their
combined state. Do not mark tickets complete or repair their lifecycle state here;
that remains owned by `implement`. Record locally which commits integrated and which
gates passed. If the tracker does not reflect the expected terminal result, report
the discrepancy and keep downstream tickets blocked.

### 8. Recompute

Reread the tracker rather than mutating the old snapshot. Normalize and validate the
new graph, then compute the next frontier. For a ticket executed in this run, satisfy
its downstream edges only when both the tracker reports the state produced by
`implement` and its commit is integrated with all required gates passing. Start
another fan-out only after the previous fan-in completes.

Stop when all scoped tickets are resolved or the graph has no executable frontier.
For a stalled graph, report each unresolved ticket, its state, and the blockers or
in-progress assignments preventing execution.

## Safety invariants

- Assign each selected ticket to exactly one `implement` subagent in the run.
- Use one worktree and branch per concurrent writer.
- Use one writer for integration.
- Leave all ticket-lifecycle writes to the assigned `implement` workflow.
- Never stage or commit unrelated changes from the user's checkout.
- Resolve exact branch and worktree paths before cleanup; never make cleanup part of
  a success assumption.
- Bound retries and expose exhausted work to humans.
- Treat combined fan-in verification as the correctness boundary.
