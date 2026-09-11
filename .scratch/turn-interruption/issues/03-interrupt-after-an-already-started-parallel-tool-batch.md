# 03: Interrupt after an already-started parallel tool batch

**What to build:** Treat a parallel tool batch that has begun as the current
indivisible work. After Escape is accepted, let every tool already started in
the batch settle with its real outcome, then end the Turn before any later
inference or tool batch and continue automatically with waiting input.

**Blocked by:** 02/Finish the current tool and skip later tools.

**Status:** implemented

- [x] Every member of a parallel batch that began before interruption is allowed to settle.
- [x] Successful, failed, and otherwise completed batch members retain their actual individual outcomes.
- [x] The TUI does not claim to have selectively cancelled any member of the already-started batch.
- [x] The visible interruption status remains active until the batch has settled.
- [x] No Agent inference, sequential tool, or later parallel batch starts for the interrupted Turn after the active batch settles.
- [x] Any tool calls requested for later work receive correlated not-executed technical results where required to keep the History valid.
- [x] The first waiting message starts automatically after the batch outcomes and skipped outcomes are reconciled.
- [x] Multiple waiting messages retain FIFO order and queue advancement occurs exactly once.
- [x] With no waiting message, the Conversation TUI returns to ready after the batch settles.
- [x] End-to-end tests verify actual execution counts, individual batch outcomes, absence of later work, History validity, visible status, and automatic queue advancement.

## Implementation

- `ToolExecutionGroup` recognizes Neuron's actual parallel execution mode,
  including its sequential fallback. Announcing a call does not start a child;
  the batch starts only after the last announcement. Once started, every result
  is drained and correlated by call ID before interruption finalizes.
- `ParallelToolFailures` temporarily adapts the original Neuron 3 node's
  protected error handler. Otherwise Neuron abandons already-finished outcomes
  after the first failed member. The adapter preserves the host handler's result,
  child hooks and node configuration, records unhandled failures as real failure
  outcomes, and rethrows them before later inference when no Escape was accepted.
  It never re-executes an arbitrary tool to assign its technical failure result.
- `TurnRunner` restores the exact host handler in `finally`, after abandoning its
  stream. The same composed Agent can subsequently run directly through Neuron
  with its original error behavior. Host overrides of `handleError` still own
  their behavior; the adapter does not replace custom nodes or the executor.
- Neuron's synchronous `Fork::run` blocks terminal dispatch while children run.
  Escape buffered during that wait is acknowledged at the first result
  checkpoint, and the pending status remains active while outcomes drain.
  This does not promise preemption of synchronous work. Deferring an unhandled
  error allows additional real result presentation and observability events
  before the original error is rethrown on a non-interrupted Turn.
- `tests/Tui/ParallelToolInterruptionTest.php` proves three distinct child PIDs,
  a shared start barrier, exactly-once outcomes and child hooks, first-member
  failure followed by successful results, host handler preservation, repeated
  Escape, FIFO/provider-input completeness and ready state without queued input.
- `tests/Conversation/ParallelToolFailureTest.php` checks normal failure
  propagation, throwing host handlers, exact handler restoration and Agent reuse,
  plus interruption before and between announcements without starting any child.
  Serializable fixtures live in `tests/Fixtures/ParallelBatchTool.php`.
- `spatie/fork` is a development dependency so these tests use actual parallel
  processes rather than Neuron's silent fallback; production configuration is
  unchanged. Tests skip only where the `pcntl` extension is unavailable.
- Validation: `composer cs:fix`, `composer stan`, and the Conversation, History
  and interruption integration suites passed: **65 tests, 566 assertions**.
