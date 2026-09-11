# 04: Restore an interrupted History faithfully

**What to build:** Make an interrupted conversation retain its meaning after a
persistent Session is reopened. Partial Agent responses must still appear as
interrupted, and completed, failed, and unstarted tool calls must remain
distinguishable while producing provider-valid input for the next Turn.

**Blocked by:** 02/Finish the current tool and skip later tools.

**Status:** implemented

- [x] An interrupted Assistant message retains its partial content and `interrupted` stop reason after a Session round trip.
- [x] Reopening the Session presents the interruption separately from the Agent's text and does not append synthetic words to that text.
- [x] A Turn interrupted before its first displayable response chunk restores the original User message without inventing an Assistant response.
- [x] Real results and failures from already-started tools survive Session reload unchanged.
- [x] Technical not-executed status survives in each skipped tool result body even where Tool result message metadata is not preserved.
- [x] Reopened History presentation distinguishes skipped tools from successfully completed tools.
- [x] Each restored tool result remains correlated with the original tool-call identifier, name, and inputs.
- [x] The next User message can be sent through supported providers without an orphaned tool call or invalid role sequence.
- [x] The next provider invocation receives partial Agent content but does not receive TUI-only interruption wording or generic message metadata.
- [x] Session round-trip tests cover partial response, no-response, completed-tool, failed-tool, and skipped-tool histories using the existing persistent Session seam.
- [x] Existing uninterrupted History and Session presentation remains unchanged.

## Implementation

- `HistoryProjection` emits one separate interruption notice after the Turn's
  text and tool activity, including calls without prose and partial text after
  a completed group. It retains the ordinary uninterrupted projection.
- `InterruptionHistory::replaceCompletedResponse` updates the existing Neuron
  History through one retained snapshot, trimming and persisting through its
  protected hooks. It avoids clearing Session storage or replaying old message
  notifications when interruption races with stream completion. Histories
  without snapshot persistence (including Neuron's incremental Eloquent history)
  retain the public-API fallback because `ChatHistoryInterface` has no update
  operation. A method-declaration check detects the inherited no-op snapshot
  hook without reading or changing any private state.
- `tests/History/InterruptedSessionTest.php` uses real TurnRunner execution,
  SessionStore and fresh FileStorage instances to verify partial and no-text
  responses at both chunk and completion boundaries, exact tool outcomes and
  identities, provider signature preservation, and a partial inference after
  tools. Resuming runs another Turn on the reopened Session.
- The recorded resumed request is mapped through Neuron's actual OpenAI Chat,
  OpenAI Responses, Anthropic and Gemini mappers. Assertions check paired call
  identities, unchanged outcome bodies, partial text and absence of TUI wording
  and stop metadata. These are protocol-construction tests, not remote API calls.
- `HistoryProjectionTest` covers notice placement and deduplication; the
  completion race also verifies that old History hooks are not replayed for
  snapshot storage, while incremental storage still receives the retained data.
- Validation: `composer cs:fix`, `composer stan` and focused History,
  TurnRunner and SessionComposition suites passed: **49 tests, 607 assertions**.
