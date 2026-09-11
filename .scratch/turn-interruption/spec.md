# Interrupt a Turn with Escape

Status: ready-for-agent

## Problem Statement

A person using the Conversation TUI cannot stop a Turn that is no longer useful.
Escape currently belongs to Picker cancellation, Command-suggestion dismissal,
and draft clearing, while a working Agent continues streaming responses, running
tools, and advancing through further work. The person must wait for the whole
Turn even when a waiting message already redirects the conversation.

Neuron 3 does not expose one cancellation contract shared by Agent streams,
tools, providers, and subprocesses. Treating Escape as immediate cancellation
would therefore be misleading: external effects may already have happened, a
tool may still be running, and abandoning a tool call without its result can
leave the History invalid for the next provider request.

## Solution

While an Agent is working and no more specific interaction owns Escape, pressing
Escape requests a Turn interruption. The TUI acknowledges the request
immediately. A streamed Agent response stops at the next chunk boundary, while
an already-started sequential tool or parallel tool batch is allowed to finish.
No further tool or Agent inference starts for the interrupted Turn.

The History retains only content and outcomes that actually occurred. A partial
Agent response is stored as an ordinary Assistant message with an interrupted
stop reason, without adding synthetic words to the response. Tool calls that
were requested but never started receive explicit technical results saying they
were not executed because the person interrupted the Turn. A started tool keeps
its real result or failure.

After the interrupted Turn settles, the ordinary queue advances and its first
waiting message starts the next Turn automatically. An open Picker or visible
Command suggestions consumes Escape first, and a composer draft remains intact
when Escape requests a Turn interruption.

## User Stories

1. As a person using the Conversation TUI, I want Escape to stop a Turn that is no longer useful, so that I can redirect the conversation without closing the TUI.
2. As a person reading a long streamed response, I want interruption to take effect at the next response chunk, so that I do not have to wait for the complete response.
3. As a person who has already seen part of a response, I want that text to remain visible, so that useful work is not discarded.
4. As a person reopening a Session, I want a partial response to remain identifiable as interrupted, so that I do not mistake it for a complete answer.
5. As a person conversing with the Agent after an interruption, I want the Agent to receive only text it actually produced, so that synthetic TUI wording is not attributed to it.
6. As a person interrupting before the first response chunk, I want my submitted message retained, so that the History still records what began the interrupted Turn.
7. As a person interrupting before the first response chunk, I do not want an invented empty or explanatory Agent response, so that the History remains truthful.
8. As a person interrupting while a tool is running, I want the TUI to acknowledge Escape immediately, so that I know my request was received.
9. As a person interrupting while a tool is running, I want the already-started tool to finish, so that the TUI does not claim to cancel work it cannot safely stop.
10. As a person interrupting a Turn with several sequential tool calls, I want later tools not to start, so that no unnecessary external effects occur.
11. As a person interrupting a parallel tool batch, I want every tool already started in that batch to settle, so that all in-flight outcomes are represented honestly.
12. As a person interrupting a parallel tool batch, I want no later inference or tool batch to start, so that the interruption still ends the Turn at the next safe boundary.
13. As a person reviewing the History, I want each completed tool to retain its real result, so that completed work is distinguishable from skipped work.
14. As a person reviewing a failed interrupted Turn, I want the failure of an already-started tool retained, so that the interruption does not conceal the failure.
15. As a person continuing the conversation after skipped tool calls, I want every unstarted call to have a correlated not-executed result, so that the provider receives a structurally complete History.
16. As a person who submitted another message while the Agent was working, I want that message to start automatically after interruption settles, so that Escape redirects the conversation without another submission.
17. As a person with several waiting messages, I want them to retain FIFO order, so that interruption does not reorder my intent.
18. As a person with no waiting messages, I want the TUI to become ready after interruption settles, so that I can write a new message normally.
19. As a person writing a draft while a Turn is active, I want Escape to preserve the draft, so that interrupting the Agent does not erase what I am composing.
20. As a person viewing Command suggestions, I want the first Escape to dismiss the suggestions, so that their existing keyboard behavior remains predictable.
21. As a person using a Picker, I want Escape to cancel the choice before affecting the Turn, so that the focused interaction continues to own its keys.
22. As a person who dismissed a Picker or Command suggestions during a Turn, I want a later Escape with no overlay open to request interruption, so that both actions remain available.
23. As a person waiting for a started tool to finish, I want the status line to explain that interruption is pending, so that the apparent delay is understandable.
24. As a person who presses Escape repeatedly while interruption is pending, I want later presses to have no additional effect, so that I cannot advance the queue twice or corrupt the History.
25. As a person whose Turn completes naturally near an Escape press, I want exactly one terminal outcome and one queue advance, so that race timing cannot duplicate work.
26. As a Host Application author, I want Turn interruption to work without configuring a new cancellation service, so that the TUI remains usable with existing Neuron 3 Agents and tools.
27. As a Host Application author observing tool side effects, I want the TUI to distinguish completed, failed, and not-executed calls, so that its records never imply rollback.
28. As a Host Application author using persistent Sessions, I want interruption metadata and correlated tool results to survive reload, so that resumed conversations remain valid.
29. As a user of existing keyboard controls, I want Ctrl+C exit behavior, input recall, scrolling, and ordinary draft clearing to remain unchanged outside an active Turn interruption.
30. As a person encountering a Neuron human-in-the-loop request, I want it to remain distinct from a Turn interruption, so that approval workflows are not mistaken for Escape handling.

## Implementation Decisions

- Turn interruption is a Conversation TUI behavior. It does not extend the Host Application configuration surface and does not introduce a new public cancellation promise.
- The input layer handles Escape according to the current interaction state. Picker cancellation and Command-suggestion dismissal take precedence. With neither active, Escape requests interruption only while a Turn is busy; it does not clear the composer draft in that state.
- The runtime owns one interruption request for the active Turn. The request is idempotent and is cleared only when that Turn reaches its single terminal transition.
- Requesting interruption immediately changes the visible status. If indivisible work is still running, the status explains that the TUI is waiting for the current tool or tool batch.
- The Turn runner observes the per-Turn interruption request at cooperative boundaries. It stops a provider response at the next streamed event boundary and does not advance the Neuron workflow afterward.
- The runner yields control to the event loop at the boundary after a tool result. This allows terminal input buffered during a synchronous tool to be handled before Neuron advances to the next sequential tool.
- A sequential tool whose execution has started is allowed to return or throw. Its actual result or failure is retained before the Turn stops.
- All members of a parallel tool batch that has begun execution count as already-started work. The batch settles as a unit; individual parallel tools are not selectively stopped.
- After an interruption request takes effect, no unstarted tool, later parallel batch, or further Agent inference may begin for the interrupted Turn.
- The runtime tracks enough of the active tool-call group to correlate completed, failed, and unstarted calls when it stops consuming the Neuron workflow.
- An interrupted tool-call group is closed with one ordinary Tool result message covering every requested call. Started calls carry their real outcomes. Unstarted calls carry a provider-visible, machine-readable technical result meaning that execution never began because the person interrupted the Turn.
- Not-executed status belongs in each tool result body rather than only in message metadata, because it must survive Session reload and reach provider protocols that require a result for every tool-call identifier.
- The technical result must preserve the original tool-call identity, name, and inputs. It must not claim that the call was cancelled after starting or that any side effect was rolled back.
- If interruption occurs during the initial response stream, the original User message is committed to the History even though Neuron normally commits it only after successful stream completion.
- Displayable response text received before interruption is committed as a normal Assistant message with stop reason `interrupted`. The stop reason remains History metadata; no interruption sentence is appended to the Agent's content.
- If no displayable response text arrived, no Assistant message is invented. The original User message carries interruption metadata and a waiting User message may follow it directly.
- History presentation recognizes interrupted response metadata and shows a concise interruption indication separately from the Agent's text.
- Tool presentation distinguishes not-executed results from successful completion. It must not render a skipped call as completed merely because it has a protocol-closing result.
- When already-started work fails after interruption was requested, the real failure remains visible and persisted. It does not prevent the interruption transition or ordinary queue advancement.
- The interrupted Turn uses the queue's ordinary completion-and-advance behavior exactly once. The first waiting message starts automatically and remaining messages retain FIFO order.
- With no waiting message, the Conversation TUI returns to its ready state after the interrupted Turn settles.
- If natural Turn completion wins the race before the interruption request is accepted, the Turn completes normally. If interruption is accepted while the Turn is busy, the Turn completes as interrupted. Either path performs cleanup and queue advancement once.
- Existing Ctrl+C terminal exit remains separate from Turn interruption. Neuron's human-in-the-loop Workflow interruption also remains a separate concept and exception path.
- The implementation may stop consuming a provider stream, but it must not promise that remote generation, synchronous I/O, arbitrary tool code, or subprocesses have been forcibly terminated.

## Testing Decisions

- Prefer tests at the Conversation TUI seam with a simulated terminal, controllable Agent provider, real History, and deterministic tools. These tests should assert what a person sees, what work executes, what the next Agent invocation receives, and what survives in History, rather than private flags or callback ordering.
- Extend the existing Conversation TUI integration-test style used for streamed responses, queued messages, tool activity, Escape precedence, Picker cancellation, Command suggestions, and persistent Sessions.
- Test streamed interruption after visible text: Escape is acknowledged, later chunks are not presented, the partial Assistant message has the interrupted stop reason, no synthetic text reaches the next Agent invocation, and the first waiting message starts.
- Test interruption before the first response chunk: the original User message is retained with interruption metadata, no empty Assistant message is created, and the waiting message starts normally.
- Test a sequential cooperative tool: Escape becomes pending while it runs, the tool finishes once, its real result is retained, later requested tools do not execute, their correlated not-executed results are persisted, and the queued message starts.
- Test a synchronous sequential tool with terminal input buffered during execution: the event loop gets a checkpoint after the result and applies Escape before the next tool starts.
- Test an already-started tool that throws after interruption is requested: the real error is shown and persisted, later tools are marked not executed, and the queue advances once.
- Test a parallel batch: all already-started members settle and retain their outcomes, while no later inference or batch starts.
- Test interruption with no waiting messages: the working indication ends and the composer returns to ready without leaving pending queue or response state.
- Test FIFO behavior with multiple waiting messages: only the first begins the next Turn and the rest retain their order.
- Test repeated Escape while waiting for a tool: only one request, one History reconciliation, and one queue advancement occur.
- Test the natural-completion race on both sides of the acceptance boundary so completion and interruption cannot both finalize the same Turn.
- Test a non-empty composer draft during interruption and verify that its text and cursor-editing state remain usable.
- Preserve and extend Escape-priority integration tests: one Escape closes Command suggestions or a Picker without interrupting the Turn; a later Escape with no overlay requests interruption.
- Test Session round-tripping so interrupted stop metadata remains available to presentation after reload and protocol-closing tool results retain their semantic status in their bodies.
- Test the History projection independently for interrupted Assistant messages and not-executed tool results, because presentation must distinguish them from complete responses and successful tools.
- Test Turn coordination independently only where deterministic race and idempotency coverage would be brittle at the terminal seam. Reuse the existing queue state-machine seam and Turn-runner provider seam rather than adding low-level tests for event-loop or Future internals.
- Keep existing integration coverage for Ctrl+C exit, ordinary Escape draft clearing, input history, scrolling, concurrent Commands, and Neuron Workflow interruptions passing as regression protection.
- After implementation, run PHP formatting, static analysis, the focused Conversation TUI, Turn runner, Turn queue, and History projection tests, followed by the repository's complete test and coding-style suites before push or release.

## Out of Scope

- Forcibly terminating an arbitrary tool, provider request, PHP blocking call, operating-system subprocess, or remote model generation.
- Rolling back filesystem, network, database, or other external side effects produced by already-started work.
- Cancelling selected members of an already-started parallel tool batch.
- Interrupting Command execution or changing which Commands are admitted during a Turn.
- Changing Picker cancellation, Command-suggestion dismissal, or Ctrl+C exit semantics.
- Adding configurable keybindings or a Host Application cancellation API.
- Changing Neuron 3 provider, HTTP-client, Agent, Workflow, or Tool interfaces upstream.
- Treating Neuron human-in-the-loop Workflow interruptions as user-requested Turn interruptions.
- Reordering, discarding, pausing, or merging waiting messages after the interrupted Turn settles.
- Guaranteeing that abandoning a response stream stops billable or server-side provider work immediately.

## Further Notes

- The accepted domain term is **Turn interruption**. Avoid describing the feature as cancellation, aborting a tool, or stopping an arbitrary execution.
- The design is recorded in ADR 0010, which requires safe-boundary interruption, truthful History reconciliation, and automatic queue advancement.
- Neuron 3.16.13 exposes no shared cancellation token through Agent streams or tools. Its Workflow interruption API represents resumable human-in-the-loop checkpoints and is unrelated to this feature.
- Neuron commits the initial User message and Assistant response only after a response stream completes. Interruption therefore requires deliberate History reconciliation rather than merely breaking the stream loop.
- Neuron records a tool-call message before executing its tools. The implementation must append correlated results for the entire call group before the next queued message reaches a provider.
- Message stop reasons and ordinary message metadata survive History persistence but are not generically forwarded by provider mappers. This permits the TUI to label a partial response without changing the Agent's words.
- Tool-result message metadata does not reliably survive every Neuron 3 deserialization path, which is why not-executed status belongs in the tool result body.
- The intended experience follows the confirmed interaction model: Escape redirects at the next safe boundary, already-started indivisible work settles, and waiting input continues automatically.

## Code Review Follow-up

- Provider failures after an accepted interruption retain the original User and any presented response text, including failures before the first streamed event. The runtime still shows the actual error and advances waiting input once in FIFO order; Workflow interruptions retain their separate exception path.
- Tool outcomes correlate to individual requested occurrences even when providers repeat or omit call IDs. Name and inputs distinguish out-of-order parallel results; batch settlement counts actual outcomes independently. Skipped sequential calls cannot inherit an earlier occurrence's result, and live and historical presentation retain separate entries.
- The runner now settles every interrupted stream exit through one common finalization block while preserving input checkpoints, completed-response persistence, and restoration of host tool error handlers.
- Regression coverage includes actual parallel child processes finishing out of request order, successful and failed repeated calls, provider failures with and without partial text or waiting input, and repeated sequential calls with unstarted successors.
- Validation passed: `composer cs:fix`, `composer stan`, the focused interruption/Conversation/History tests, `composer test` (281 tests, 2211 assertions), and `composer cs`.
