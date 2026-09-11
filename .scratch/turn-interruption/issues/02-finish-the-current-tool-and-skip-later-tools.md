# 02: Finish the current tool and skip later tools

**What to build:** When a person requests Turn interruption during sequential
tool work, let the already-started tool settle, preserve its actual outcome,
prevent every later tool and Agent inference in that Turn from starting, and
continue with the first waiting message. Keep the History valid by recording a
correlated technical outcome for every tool the Agent requested but the TUI did
not execute.

**Blocked by:** 01/Interrupt a streamed Turn and advance waiting input.

**Status:** ready-for-agent

- [ ] Escape is accepted while a cooperative sequential tool is running and the visible status explains that interruption is waiting for the current tool.
- [ ] The already-started tool executes exactly once and retains its real result before the Turn stops.
- [ ] If the already-started tool fails, its real failure remains visible and persisted rather than being replaced by an interruption result.
- [ ] No later sequential tool or Agent inference starts after the current tool settles.
- [ ] Terminal input buffered during a synchronous tool is processed at a cooperative event-loop boundary before the next tool can start.
- [ ] One ordinary Tool result message closes the complete requested tool-call group.
- [ ] Executed calls retain their real results; unstarted calls receive provider-visible, machine-readable results stating that execution never began because the person interrupted the Turn.
- [ ] Every technical result preserves the original tool-call identifier, name, and inputs.
- [ ] A result for an unstarted call does not claim that a started operation was cancelled or that external effects were rolled back.
- [ ] History presentation distinguishes an unstarted tool from a successfully completed tool.
- [ ] The first waiting message starts automatically after History reconciliation, and the provider receives no orphaned tool call.
- [ ] With no waiting message, the Conversation TUI becomes ready after the started tool and History reconciliation settle.
- [ ] Repeated Escape presses and a tool completion race finalize the Turn and advance the queue exactly once.
- [ ] End-to-end tests use deterministic tools and a real History to assert execution counts, recorded outcomes, provider input, visible status, and queue behavior.

