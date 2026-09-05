# 04: Preserve the initial History and explicit Session ownership

**What to build:** Entering the TUI preserves the History selected on the Agent.
The Host explicitly chooses whether that History belongs to Sessions. Startup
does not import an external History or automatically resume a stored Session.

**Blocked by:** 03 — Compose shared modules explicitly and mount through Commands.

**Status:** ready-for-agent

Parent: Refine Interaction composition and shared Commands, the spec in this
feature directory.

- [ ] Startup preserves the Agent's existing History and messages instead of replacing them with an empty Session.
- [ ] An empty Agent opens empty. A preloaded Agent shows its selected History without automatically registering or copying it into Sessions.
- [ ] A Session explicitly started or resumed by the Host before startup remains the Agent's conversation and does not create a duplicate Session.
- [ ] /resume discovers only conversations managed by the configured Sessions. An arbitrary History supplied directly to the Agent is not promised recovery through /resume.
- [ ] Clear and Resume use the same Sessions instance exposed through Command controls. Existing Session title semantics, Storage contracts and the two-invocation selection protocol remain unchanged.
- [ ] Clear/resume recovers an explicitly selected Session, including messages added during interaction. Sessions created through /clear with the default in-memory module are also recoverable during that interaction.
- [ ] No retain/import operation, active-History field, History factory or Interaction container is introduced.
- [ ] Tests exercise startup preservation without implicit persistence, a preselected Session, and clear/resume round trips using real Sessions and Storage through the virtual-terminal boundary.
- [ ] Tests cover managed Session recovery with supplied and default Sessions, including messages from fake Agent turns. No network model calls or private runtime assertions are required.
- [ ] Examples explicitly install a History from Sessions when they want the initial conversation persisted by that module. Documentation supersedes ADR-0005's unconditional History replacement without introducing automatic import.
- [ ] Both repositories' relevant tests and static analysis pass, with consumer locks updated to the revised shared package.

## Execution notes

The user clarified during implementation that assigning a Neuron Chat History
to the Agent does not make it a Session. Automatic retention/import was rejected.
This replaces the earlier requirement to make every initial History resumable.
Preserve the supplied History and keep Session selection an explicit Host choice.
Do not change Storage schemas, Session title rules or InputHistory navigation.
Continue on the existing feat/extract-neuron-interaction branches and PRs.
