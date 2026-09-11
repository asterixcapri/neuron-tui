# 02: Finish the current tool and skip later tools

**What to build:** When a person requests Turn interruption during sequential
tool work, let the already-started tool settle, preserve its actual outcome,
prevent every later tool and Agent inference in that Turn from starting, and
continue with the first waiting message. Keep the History valid by recording a
correlated technical outcome for every tool the Agent requested but the TUI did
not execute.

**Blocked by:** 01/Interrupt a streamed Turn and advance waiting input.

**Status:** implemented

- [x] Escape is accepted while a cooperative sequential tool is running and the visible status explains that interruption is waiting for the current tool.
- [x] The already-started tool executes exactly once and retains its real result before the Turn stops.
- [x] If the already-started tool fails, its real failure remains visible and persisted rather than being replaced by an interruption result.
- [x] No later sequential tool or Agent inference starts after the current tool settles.
- [x] Terminal input buffered during a synchronous tool is processed at a cooperative event-loop boundary before the next tool can start.
- [x] One ordinary Tool result message closes the complete requested tool-call group.
- [x] Executed calls retain their real results; unstarted calls receive provider-visible, machine-readable results stating that execution never began because the person interrupted the Turn.
- [x] Every technical result preserves the original tool-call identifier, name, and inputs.
- [x] A result for an unstarted call does not claim that a started operation was cancelled or that external effects were rolled back.
- [x] History presentation distinguishes an unstarted tool from a successfully completed tool.
- [x] The first waiting message starts automatically after History reconciliation, and the provider receives no orphaned tool call.
- [x] With no waiting message, the Conversation TUI becomes ready after the started tool and History reconciliation settle.
- [x] Repeated Escape presses and a tool completion race finalize the Turn and advance the queue exactly once.
- [x] End-to-end tests use deterministic tools and a real History to assert execution counts, recorded outcomes, provider input, visible status, and queue behavior.

## Implementation

- `src/Conversation/ToolExecutionGroup.php` distinguishes announced calls from
  started work and records actual results by call ID. Its reconciliation closes
  the original group before another User reaches the provider.
- `src/History/ToolOutcome.php` supplies correlated ordinary Tool result carriers
  for `not_executed` and unhandled `failed` outcomes. Shared `ToolActivityText`
  presents these honestly in live and historical views.
- `TurnRunner` yields at the result boundary even after synchronous work, keeps
  the real failure when a started tool throws, and does not resume the workflow
  after accepting interruption. `ConversationView` identifies pending tool work.
- `InterruptedHistoryTrimmer` permits a following User after an interrupted tool
  group while retaining default validation and the original History object.
- `tests/Tui/SequentialToolInterruptionTest.php` covers cooperative and synchronous
  tools, buffered input, failures, repeated Escape, FIFO, ready state, full result
  correlation and the next provider request. `TurnRunnerTest` also covers an
  interruption before any execution and partial prose after a completed group.
- Validation: `composer cs:fix`, `composer stan`, and the focused Conversation,
  interruption integration and History suites passed: **55 tests, 310 assertions**.

Parallel batch settlement extends the group tracking in ticket 03; storage
round trips and provider mapper coverage remain in ticket 04.
