# 02 — Hold a streamed Markdown conversation

**What to build:** Extend the initial read-only Conversation TUI into a usable
text conversation. A terminal user can compose and submit a message, watch the
Agent's Markdown response arrive incrementally, understand when work is in
progress, and continue after completion or a handled Agent failure.

**Blocked by:** 01 — Open an Agent's existing History.

**Status:** ready-for-agent

- [ ] The composer accepts text over one to five visible lines, Enter submits,
      Shift+Enter adds a line break, and blank submissions do not invoke the
      Agent.
- [ ] A submitted user message is shown immediately and the Agent response is
      consumed through Neuron AI streaming.
- [ ] Text chunks update one accumulating Markdown presentation with readable
      headings, lists, emphasis, tables, links, and highlighted code.
- [ ] A visible working animation remains active while the response is pending,
      and the composer prevents a second request from being submitted.
- [ ] Focus returns to the composer after streaming completes, and a stream
      with no user-facing content displays an explicit English empty-response
      indicator.
- [ ] Agent exceptions display their class and message without a stack trace,
      after which the composer is available for another message.
- [ ] Error handling does not flush, reconstruct, prune, or append synthetic
      messages to the Agent's History.
- [ ] Amp, CommonMark, and Tempest Highlight are declared runtime dependencies
      rather than assumed to be transitively available.
- [ ] Public-seam tests prove incremental rendering, single-flight behavior,
      empty responses, error recovery, and unchanged History ownership without
      live provider calls.
