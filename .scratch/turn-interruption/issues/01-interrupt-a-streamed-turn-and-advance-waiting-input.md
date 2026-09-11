# 01: Interrupt a streamed Turn and advance waiting input

**What to build:** Let a person press Escape during a streamed Agent response to
request a Turn interruption, retain the response received so far, and continue
automatically with the first waiting message. The interaction must acknowledge
the request immediately without erasing a composer draft or taking Escape away
from a Picker or Command suggestions.

**Blocked by:** None (can start immediately).

**Status:** implemented

- [x] With no Picker or Command suggestions open, Escape during a streamed response requests interruption and immediately changes the visible status.
- [x] The response stops at the next streamed event boundary and no later response content from that Turn is presented.
- [x] Displayable text already received remains visible and is committed as an ordinary Assistant message with stop reason `interrupted`.
- [x] The provider for the next Turn receives only the partial text the Agent produced, without a synthetic interruption sentence.
- [x] When interruption precedes the first displayable response chunk, the original User message is retained with interruption metadata and no Assistant message is invented.
- [x] The first waiting message starts the next Turn automatically; additional waiting messages retain FIFO order.
- [x] With no waiting message, the Conversation TUI returns to its ready state.
- [x] Escape preserves a non-empty composer draft while requesting Turn interruption.
- [x] An open Picker or visible Command suggestions consumes the first Escape without interrupting the Turn; a later Escape with neither open requests interruption.
- [x] Repeated Escape presses while interruption is pending do not duplicate History reconciliation or queue advancement.
- [x] A race between natural completion and Escape produces exactly one terminal outcome and advances the queue at most once.
- [x] Ctrl+C exit, ordinary Escape draft clearing outside a busy Turn, input recall, scrolling, concurrent Commands, and Neuron Workflow interruptions retain their existing behavior.
- [x] Focused Conversation TUI, Turn-runner, queue, and History-presentation tests cover the behavior at existing seams.

## Implementation

- `src/Conversation/TurnInterruption.php` owns the idempotent request, cooperative
  checkpoint and terminal transition; `ConversationRuntime` reuses ordinary queue
  completion and `TurnRunner` retains the interrupted stream in History.
- `src/History/InterruptionHistory.php` prepares Neuron's default trimmer while
  retaining the original History/Session object and any custom Host trimmer.
  `InterruptedHistoryTrimmer` permits an interrupted, unanswered User before the
  next Turn without inventing an Assistant message.
- `tests/Tui/TurnInterruptionTest.php` covers Escape priority, pending status,
  partial text, FIFO continuation and draft cursor preservation.
  `tests/Conversation/ConversationRuntimeTest.php` and `TurnRunnerTest.php` cover
  both completion races and interruption before any response; History projection
  tests keep the outcome separate from Agent content.
- Validation: `composer cs:fix`, `composer stan`, and focused Conversation,
  History, View, interruption integration and existing `TuiTest.php` suites:
  **197 tests, 814 assertions** passed.

Tool-call reconciliation remains the responsibility of tickets 02 and 03;
Session reload coverage is completed in ticket 04.
