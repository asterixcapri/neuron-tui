# Refine Interaction composition and shared Commands

Status: ready-for-agent

## Problem Statement

The extraction of Neuron Interaction is implemented, but composing a Host
Application still leaves too much shared behavior inside Neuron TUI. The TUI
mounts Commands, constructs interaction modules from Storage, and owns Help and
Leave through a separate concurrent Command contract. This makes ownership
harder to follow and prevents another Adapter from using those Commands directly.

The runtime also replaces the Agent's initial History with an empty Session.
A Host Application cannot safely bring an existing conversation into the TUI,
and that conversation must remain recoverable after clearing. Meanwhile,
stripping and restoring slash prefixes splits one Command identifier convention
between the shared library and terminal presentation.

## Solution

Compose the TUI around a required Agent and independently optional Commands,
Sessions and Input history. Reuse supplied modules, construct omitted defaults
once, and keep each module responsible for its own behavior without introducing
an Interaction container. Move incremental Command mounting into Commands.

Ship Help and Leave as ordinary Neuron Interaction Commands. Keep permission to
execute them during a Turn as a simple, explicit TUI policy. Restore the slash
as part of Command identifiers throughout dispatch and presentation. Preserve
the Agent's initial conversation and make it resumable through the configured
Sessions, including when Sessions uses the default in-memory Storage.

## User Stories

1. As a Host Application developer, I want to start a TUI with just a configured Agent, so that minimal setup stays simple.
2. As a Host Application developer, I want the Agent to remain mandatory, so that the TUI never invents my model configuration.
3. As a Host Application developer, I want to supply Commands independently of Sessions and Input history, so that I configure only what I need.
4. As a Host Application developer, I want omitted Commands to be empty, so that no Command is mounted without my consent.
5. As a Host Application developer, I want omitted Sessions to use in-memory Storage, so that persistence configuration is optional.
6. As a Host Application developer, I want omitted Input history to use in-memory Storage, so that input recall works without persistence setup.
7. As a Host Application developer, I want supplied module instances reused, so that my application and the Adapter operate on the same state.
8. As a Host Application developer, I want defaults created once per TUI instance, so that repeated Command invocations do not reset interaction state.
9. As a Host Application developer, I want to compose individual modules without an Interaction container, so that dependencies remain explicit.
10. As a Host Application developer, I want to add Commands through Commands rather than Tui, so that mounting works outside a terminal.
11. As a Host Application developer, I want addCommand to mutate the existing collection and return it, so that fluent configuration does not require replacement copies.
12. As a Host Application developer, I want to mount individual Commands, kits and mixed arrays, so that existing composition patterns remain available.
13. As a Host Application developer, I want mounting order and first-match duplicate resolution preserved, so that composition remains predictable.
14. As a Command author, I want slash-prefixed identifiers accepted unchanged, so that one identifier is used by dispatch, help and suggestions.
15. As a Host Application developer, I want names without a slash rejected when mounted, so that invalid configuration fails immediately.
16. As a TUI user, I want Command arguments to retain the existing parsing behavior, so that restoring the slash does not change argument handling.
17. As an Adapter developer, I want Help available from Neuron Interaction, so that I can describe mounted Commands without terminal dependencies.
18. As a user, I want Help to enumerate the effective Commands and their descriptions, so that it reflects the configured interaction.
19. As an Adapter developer, I want Leave available from Neuron Interaction, so that I can map its stop request to my Adapter's lifecycle.
20. As a Command author, I want Help and Leave to use the ordinary Command controls contract, so that there is no parallel execution API.
21. As a TUI user, I want Help and Leave available during an active Turn, so that I can inspect commands or exit while the Agent is working.
22. As a Host Application developer, I want aliases of Help and Leave to retain that permission, so that permission is independent of spelling.
23. As a TUI maintainer, I want other Commands refused during a Turn even if named /help or /exit, so that names alone cannot bypass the policy.
24. As a TUI user, I want Leave to close the terminal without introducing a wait for the Agent, so that the existing exit experience is preserved.
25. As a Host Application developer, I want existing Agent messages preserved at startup, so that entering the TUI does not discard my conversation.
26. As a TUI user, I want the initial conversation recoverable after /clear through /resume, so that clearing does not silently lose it.
27. As a TUI user, I want that recovery with default in-memory Sessions too, so that the default setup remains coherent during the interaction.
28. As a Host Application developer, I want a History I resumed before startup to remain the initial conversation, so that the TUI respects my selection.
29. As an Adapter developer, I want Input history navigation to remain optional, so that a frontend may navigate locally without a backend call for each arrow key.
30. As a maintainer, I want examples and architectural guidance aligned with the revised contracts, so that future work does not reintroduce the superseded design.

## Implementation Decisions

- Extend Tui construction and its equivalent make factory to accept Commands,
  Sessions and InputHistory independently. Preserve the required configured
  Agent and optional Terminal. Supplied module objects are reused, not copied
  or reconstructed from their Storage.
- Omitted Commands produces an empty collection. Omitted Sessions and
  InputHistory use InMemoryStorage. Resolve defaults once per TUI instance and
  carry those same modules through runtime and Command controls. No automatic
  Help, Leave or Session Command kit mounting is introduced.
- Explicit module composition replaces TUI-owned mounting and Storage-based
  module configuration. The Host Application configures persistent Storage
  through the modules it supplies. Remove Tui::addCommand; migrate existing
  setStorage usage to module composition rather than maintaining a competing
  source of module ownership.
- Commands::addCommand modifies the existing collection and returns $this.
  Preserve constructor-based mounting, individual Commands, Command kits and
  mixed arrays, ordered enumeration, immediate member validation and first
  matching duplicate resolution. Constructor mounting and incremental mounting
  enforce the same identifier requirements.
- Configure Commands before running the TUI. Live mutation during execution
  is outside the supported contract; introduce no collection copies, freeze
  flags, locking or dynamic suggestion synchronization mechanism.
- Every mounted Command identifier includes its leading slash. Reject names
  missing it immediately; do not silently insert or remove prefixes. Lookup
  remains exact. This revision does not introduce additional identifier grammar
  or case normalization.
- Keep the slash in the parsed Command name. Preserve existing argument
  splitting and trimming. Help, suggestions, errors and Selection requests use
  the actual identifier without adding another slash. Native defaults are
  /help, /exit, /clear and /resume; configured aliases follow the same convention.
- Move HelpCommand and LeaveCommand into Neuron Interaction. Both implement
  CommandInterface and receive CommandControlsInterface. Help reads the mounted
  Commands through controls and presents names and descriptions; Leave calls
  stop. Neither depends on terminal presentation.
- Remove ConcurrentCommandInterface, ConcurrentControls and
  ConcurrentCommandAdapter. Do not introduce AbstractConcurrentCommand or a
  replacement marker, restricted controls contract or wrapper.
- The TUI permits HelpCommand and LeaveCommand during an active Turn using
  explicit implementation checks. Aliases of those implementations remain
  eligible; unrelated Commands with the same names do not. Other Commands are
  refused during the Turn. The shared dispatcher imposes no TUI concurrency
  policy.
- Preserve stop semantics: close the Adapter and pending Picker; stop TUI
  queue processing without introducing cancellation of, or waiting for, the
  Agent's in-flight work. Other Adapters define their own stop effect.
- The Host Application chooses the Agent's initial or resumed History. Startup
  must preserve that conversation and its existing messages while integrating
  it with the supplied or default Sessions so it remains resumable after clear.
  Do not replace it with an empty conversation. The integration mechanism is an
  implementation choice, not a requirement for a new active-History field or
  a new public facade. Acceptance concerns retained conversation content and
  resumability, not mandatory identity of the History object.
- Retain CommandExecution's completed, unknown and failed outcomes and the
  existing two-invocation Selection request protocol. Clear and Resume use the
  same Sessions instance exposed through Command controls.
- Retain the already implemented InputHistory behavior: older, newer,
  isNavigating and leave are optional navigation methods on the existing class;
  cursor and draft belong to the instance and only submitted inputs persist.
  Do not recreate a separate TUI navigator.
- All interface names retain the Interface suffix. Retain SessionCommandKit
  naming. Keep terminal scheduling, rendering and Agent streaming in Neuron TUI.
- Explicitly supersede the affected architectural decisions during this
  revision: ADR-0002's concurrent marker and restricted controls policy;
  ADR-0003's TUI-owned Command mounting and rejection of module constructor
  composition; ADR-0005's runtime-owned construction and unconditional initial
  History replacement. Preserve unrelated rules, including no automatic
  mounting, first-match duplicates, Storage contracts and single-run TUI
  lifecycle. Preserve ADR-0006's selection protocol and ADR-0007's outcomes.

## Testing Decisions

- The user approved two existing public testing boundaries: Neuron Interaction
  APIs exercised without a terminal, and full TUI behavior through a virtual
  terminal with a fake Agent provider. Prefer these boundaries to new test-only
  interfaces or assertions against private runtime structure.
- Good tests assert observable behavior and public contracts: dispatched
  effects, output, History contents, resumable Sessions, and identity where
  reusing a supplied module or returning $this is itself the contract.
- Extend the existing Commands and Command kits tests for mutation of the
  original collection, fluent return identity, constructor/addition parity,
  ordering, duplicates, invalid members and rejection of slashless names.
- Use the existing fake Command controls and backend example test patterns to
  cover shared Help and Leave, exact identifiers, unchanged arguments passed to
  dispatch, and technical execution outcomes without terminal dependencies.
- Extend TuiTest, SessionCompositionTest and Input history integration tests
  for the minimal Agent-only setup, independently omitted modules, reuse of
  supplied modules, stable default state across invocations and empty Commands.
- Cover existing Agent messages and a preselected Session at startup. Exercise
  clear followed by resume and assert restoration of the initial conversation,
  both with supplied Sessions and default in-memory Sessions. Use real module
  behavior rather than mocking away the persistence interaction.
- Exercise Help and Leave during an active fake Agent Turn, including aliases;
  verify refusal of other Commands, including an unrelated Command named /help.
  Verify exit and cessation of queued-input processing without requiring
  cancellation or completion of the in-flight Agent work.
- Update existing Submission, suggestions and Picker behavior coverage for
  slash-preserving names, unchanged argument parsing, no doubled prefixes and
  successful two-step Resume dispatch.
- Retain navigation regression coverage for instance-local cursor/draft and
  independently shared persisted Input history. The revision must not require
  frontend use of the optional navigation methods.
- Run both repositories' test suites and static analysis. Validate Composer
  metadata and update consumer dependency locks and executable examples to the
  revised shared package. Do not require network model calls for behavioral tests.

## Out of Scope

- Repeating the completed extraction, reopening its completed tickets, or
  creating replacement repositories, branches or pull requests.
- An Interaction dependency container, generic service locator or new
  concurrency abstraction.
- Live Command reconfiguration while the TUI is running.
- Agent cancellation, background-worker orchestration, HTTP endpoints or a
  web frontend implementation.
- Changes to Storage schemas, Session title semantics, selection protocol,
  technical execution outcomes or the already completed navigation relocation.
- Automatic Command registration, slashless compatibility aliases or a new
  general-purpose Command identifier grammar.
- Package release, merge or publication to a package registry.

## Further Notes

This is a follow-up to the completed [extraction spec](../extract-neuron-interaction/spec.md),
not a replacement record of that implementation. The agreed design and rejected
immutable-collection proposal are recorded in the
[composition review](../extract-neuron-interaction/composition-review.md).
The original spec remains historical; this spec supersedes its conflicting
neutral-identifier, terminal-only Help/Leave and marker-concurrency requirements
for the new work.

Implement on the existing feat/extract-neuron-interaction branches in both
repositories and update the existing Neuron TUI PR #11 and Neuron Interaction
PR #1. Future to-tickets work should create new revision tickets under this
feature rather than reopening completed extraction tickets.

The grilling is complete and the testing boundaries were confirmed by the
user. This document publishes the agreed work to the local Markdown issue
tracker; it does not claim that the revision has been implemented.
