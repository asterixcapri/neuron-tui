# Tracker read contract

Read the project's configured ticket system through semantic operations. The backing
system may be local files, GitHub, GitLab, Linear, Jira, or another tracker. Keep
ticket-lifecycle writes behind the project's `implement` workflow.

## Required operations

The project's `docs/agents/issue-tracker.md` should make these operations
unambiguous for implementation-ticket graphs:

| Operation | Required meaning |
| --- | --- |
| Scope | Resolve an effort reference to exactly its implementation tickets. |
| List | Return ticket identities and stable references in that scope. |
| Fetch | Read title, body, comments, execution state, and blocking edges. |
| Eligible | Decide whether a ticket may be executed by an agent. |
| Blockers | Resolve every `blocked by` edge to a stable ticket identity. |
| Satisfied | Decide whether a blocker has reached the required terminal state. |

Prefer native dependency, assignment, and state features. Use a documented body or
metadata convention only when the tracker lacks a native feature.

## Keep lifecycle ownership with `implement`

Pass the exact ticket title and reference to an explicit `implement` invocation.
Allow that workflow to claim the ticket, update its stories and acceptance criteria,
record failure, and mark completion according to the project tracker instructions.
The graph orchestrator performs none of those writes and introduces no replacement
status convention.

After each subagent result and serial integration, reread the tracker. For tickets
executed in the current run, require both the terminal tracker state and successful
integration before satisfying downstream blockers.

## Keep state vocabularies separate

Map backend strings to meanings; do not impose one universal list of labels.

- `ready-for-agent` is commonly an eligibility/triage role.
- `claimed` or an assignee represents execution ownership.
- `closed`, `done`, or `resolved` commonly satisfies a blocker.
- A terminal tracker state written by `implement` releases downstream work only after
  that ticket's commit is also integrated and the required gates pass.
- `ready-for-human` may mean escalation or review in one project and something else
  in another. Consult the project mapping.

## Canonical snapshot

Normalize tracker reads to JSON before deterministic graph computation:

```json
{
  "version": 1,
  "effort": {
    "id": "checkout-redesign",
    "reference": "tracker-specific reference"
  },
  "nodes": [
    {
      "id": "01",
      "reference": ".scratch/checkout/issues/01-foundation.md",
      "title": "Create the checkout foundation",
      "state": "eligible",
      "blockedBy": []
    },
    {
      "id": "02",
      "reference": ".scratch/checkout/issues/02-payment.md",
      "title": "Accept payment",
      "state": "eligible",
      "blockedBy": ["01"]
    }
  ]
}
```

Allowed canonical states:

| State | Scheduler meaning |
| --- | --- |
| `eligible` | May enter the frontier when every blocker is resolved. |
| `claimed` | Owned by a worker or another run; do not schedule. |
| `resolved` | Integrated and verified; satisfies downstream blockers. |
| `failed` | Exhausted or awaiting intervention; do not schedule automatically. |
| `ineligible` | Open but not agent-executable; do not schedule. |

Require unique non-empty string ids, non-empty references and titles, and a
`blockedBy` array of ids. Preserve backend-specific fields under an optional
`metadata` object; never use them for core graph computation.

## Typical reads

These are examples, not defaults:

| Backend | Edge | Execution state |
| --- | --- | --- |
| Local Markdown | `Blocked by` field | configured `Status` value |
| GitHub | native dependency or body fallback | issue state, labels, and assignee |
| GitLab | native blocking link or body fallback | issue state, labels, and assignee |
| Linear/Jira | configured relation | configured workflow state and assignee |

If the tracker document does not define a required read operation, infer it only when
the result is read-only and verifiable. Never infer state-changing operations.
