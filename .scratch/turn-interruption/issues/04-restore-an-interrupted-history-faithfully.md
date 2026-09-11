# 04: Restore an interrupted History faithfully

**What to build:** Make an interrupted conversation retain its meaning after a
persistent Session is reopened. Partial Agent responses must still appear as
interrupted, and completed, failed, and unstarted tool calls must remain
distinguishable while producing provider-valid input for the next Turn.

**Blocked by:** 02/Finish the current tool and skip later tools.

**Status:** ready-for-agent

- [ ] An interrupted Assistant message retains its partial content and `interrupted` stop reason after a Session round trip.
- [ ] Reopening the Session presents the interruption separately from the Agent's text and does not append synthetic words to that text.
- [ ] A Turn interrupted before its first displayable response chunk restores the original User message without inventing an Assistant response.
- [ ] Real results and failures from already-started tools survive Session reload unchanged.
- [ ] Technical not-executed status survives in each skipped tool result body even where Tool result message metadata is not preserved.
- [ ] Reopened History presentation distinguishes skipped tools from successfully completed tools.
- [ ] Each restored tool result remains correlated with the original tool-call identifier, name, and inputs.
- [ ] The next User message can be sent through supported providers without an orphaned tool call or invalid role sequence.
- [ ] The next provider invocation receives partial Agent content but does not receive TUI-only interruption wording or generic message metadata.
- [ ] Session round-trip tests cover partial response, no-response, completed-tool, failed-tool, and skipped-tool histories using the existing persistent Session seam.
- [ ] Existing uninterrupted History and Session presentation remains unchanged.

