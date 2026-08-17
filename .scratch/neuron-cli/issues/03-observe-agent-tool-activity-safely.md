# 03 — Observe Agent tool activity safely

**What to build:** Make Agent tool usage observable inside the Conversation
TUI. Developers can connect each invocation to its arguments and outcome
without allowing malformed, sensitive, or very large tool payloads to take
over the terminal.

**Blocked by:** 02 — Hold a streamed Markdown conversation.

**Status:** ready-for-agent

- [ ] A live tool call appears as soon as Neuron AI emits its tool-call event,
      showing the tool name and a compact argument preview.
- [ ] The matching activity is updated when the tool result arrives, connecting
      the invocation to a compact result preview.
- [ ] Multiple and parallel tool activities remain correctly associated by call
      identifier, with a safe fallback for tools that lack one.
- [ ] Tool calls and results already present in History are represented with
      the same visual language as live tool activity.
- [ ] Names, arguments, and results are sanitized for UTF-8 and control bytes,
      collapsed to one line, and truncated to a bounded display width.
- [ ] Full or unbounded tool payloads are never expanded automatically.
- [ ] Deterministic public-seam tests cover historical, sequential, multiple,
      long, malformed, and completed tool activity without network access.
