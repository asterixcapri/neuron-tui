# 04: Preserve and resume the initial conversation

**What to build:** Entering the TUI preserves the conversation already selected
on the Agent. After clearing it, the user can resume that initial conversation,
including when Sessions was omitted and defaults to in-memory Storage.

**Blocked by:** 03 — Compose shared modules explicitly and mount through Commands.

**Status:** ready-for-agent

Parent: Refine Interaction composition and shared Commands, the spec in this
feature directory.

- [ ] Startup preserves the Agent's existing conversation and messages rather than replacing them with an empty Session.
- [ ] The initial conversation participates in the supplied or default Sessions collection and remains discoverable and recoverable after Clear followed by Resume.
- [ ] A Session selected by the Host Application before startup remains the initial conversation; the TUI does not silently start an unrelated empty conversation.
- [ ] Recovery covers the conversation present when it is cleared, including messages added during the TUI interaction, not only a snapshot taken at startup.
- [ ] Clear and Resume use the same Sessions instance exposed through Command controls. Existing Session title semantics, Storage contracts and the two-invocation selection protocol remain unchanged.
- [ ] Integration preserves conversation content and resumability; it need not preserve the identity of an arbitrary initial History object. Do not introduce a mandatory active-History field, History factory or Interaction container merely to satisfy the tests.
- [ ] Tests exercise existing Agent messages, a preselected Session and clear/resume round trips using real Sessions and Storage behavior through the existing virtual-terminal boundary.
- [ ] Tests cover both supplied Sessions and default in-memory Sessions, including retention of messages generated during the interaction. No network model calls or private runtime assertions are required.
- [ ] Shared-module tests cover any added public behavior needed by the integration, without introducing new test-only interfaces.
- [ ] Examples and documentation explain startup preservation and explicitly supersede ADR-0005's unconditional History replacement. Both repositories' relevant tests and static analysis pass, with consumer locks updated if the shared package changes.

## Execution notes

The implementation mechanism is intentionally not predetermined; choose the
smallest integration that satisfies the public behavior. Do not change Storage
schemas, Session title rules or InputHistory navigation. Apply the currently
landed identifier convention; ticket 02 is not a prerequisite. Continue on the
existing feat/extract-neuron-interaction branches and PRs without merging or
releasing.
