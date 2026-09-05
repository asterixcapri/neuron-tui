# 01: Share Help and Leave and simplify concurrent Command policy

**What to build:** A Host Application can use Help and Leave from Neuron
Interaction without terminal dependencies. The TUI permits these same ordinary
Commands during a Turn through an explicit implementation check, without a
parallel Command contract.

**Blocked by:** None (can start immediately).

**Status:** completed

Parent: Refine Interaction composition and shared Commands, the spec in this
feature directory.

- [x] HelpCommand and LeaveCommand belong to Neuron Interaction and implement CommandInterface using CommandControlsInterface.
- [x] Help enumerates the effective Commands and their descriptions through controls; Leave requests stop through controls. Both work with non-terminal fake controls and the backend example.
- [x] The TUI admits HelpCommand and LeaveCommand during a Turn by implementation, including configured aliases. Unrelated Commands are refused even when named like Help or Leave.
- [x] ConcurrentCommandInterface, ConcurrentControls and ConcurrentCommandAdapter are removed, with no replacement marker, abstract concurrent base, restricted controls or wrapper.
- [x] All Command execution uses the ordinary controls contract and preserves completed, unknown and failed CommandExecution outcomes.
- [x] Leave closes the terminal and pending Picker and stops queued-input processing without introducing cancellation of, or waiting for, in-flight Agent work.
- [x] No Commands are mounted automatically. Command-specific dependencies remain constructor dependencies rather than additions to a generic container.
- [x] Public API tests with fake controls and TUI tests with a virtual terminal and fake Agent cover Help, Leave, aliases, rejected Commands during a Turn and exit behavior.
- [x] Consumer imports, examples and dependency locks are updated together with the package change; both repositories' relevant tests and static analysis pass.
- [x] Interface names retain the Interface suffix. Documentation for these behaviors reflects the removal of marker-based concurrency and explicitly identifies the affected ADR-0002 policy as superseded.

## Execution notes

Use the existing feat/extract-neuron-interaction branches in Neuron Interaction
and Neuron TUI. Do not create replacement PRs, merge or release. Identifier
syntax is owned by ticket 02: preserve the current convention if it has not
landed, or use its slash-prefixed convention if it has. Do not independently
introduce another identifier migration in this ticket. Keep each repository
verifiable with the corresponding consumer dependency update.

## Completion

Shared Help/Leave and active-Turn policy integrated in TUI 5428d85 and Interaction 3cd79b7. Isolated ticket suites passed (208 TUI tests, 107 shared tests); integrated checks passed (111 TuiTest tests, 47 Command tests, PHPStan in both repositories). Consumer locks resolve the integrated shared commit. Final full-suite audit follows in ticket 05.
