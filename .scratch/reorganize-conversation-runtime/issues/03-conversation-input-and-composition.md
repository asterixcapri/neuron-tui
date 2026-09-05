# 03: Separate conversation input and complete TUI composition

**What to build:** Human input, conversation progression, and Command execution
can be understood independently in the running TUI. ConversationInput owns
the human-input path, Tui.run() assembles the interaction, and the runtime
concentrates on the current Agent and Turns. The complete two-package change
preserves terminal behavior and is documented and verified as an integrated
whole.

**Blocked by:** 02 — Execute every TUI Command through TuiAdapter.

**Status:** ready-for-agent

- [ ] Introduce concrete internal ConversationInput for submission, draft
  changes, recall, scroll, quit keys, and InputEvent propagation. It uses the
  view, InputHistory, runtime, Commands, and Sessions. Keep recording,
  interpretation, navigation abandonment, and draft handling together.
- [ ] Stopped input is ignored as before. Submission leaves recall navigation;
  blank input preserves existing behavior. Nonblank human input is recorded
  before interpretation and admission, including unknown and refused Commands.
  Messages use runtime.send(); CommandInput uses Commands.run() with a fresh
  TuiAdapter. Programmatic prompts and Picker selections remain outside this
  recording path.
- [ ] Preserve listener priorities and keyboard ownership among Picker,
  suggestions, recall, and composer. Editing a recalled input, leaving
  navigation, multiline input, scroll keys, and quit keys retain their current
  behavior. Existing suggestion and Picker widget logic remains in the view.
- [ ] Tui.run() constructs the view, runtime, and input handling and connects
  all input listeners and runtime ticks before starting the view. Configuration
  remains available before run, widget construction remains deferred, and
  no additional launcher, initialization setter, or dependency lookup module
  is introduced.
- [ ] Preserve interactive-TTY validation, startup failure propagation,
  branding and optional Terminal behavior, configuration freezing, and the
  single-run contract. Constructor and make() composition remain equivalent.
- [ ] Reuse supplied Commands, Sessions, and InputHistory; create omitted
  defaults once per TUI instance. Preserve independently configured persistence,
  no automatic Command mounting, and the initial Agent History without implicit
  Session creation, import, or selection.
- [ ] ConversationRuntime retains only the current Agent and Turn lifecycle
  responsibilities behind its explicit operations. It no longer depends on
  Commands, Sessions, InputHistory, or TuiAdapter and exposes no Command
  presentation or selection operations. Keep TurnQueue, AgentTurn, Submission,
  and ConversationView as existing concrete behavior modules.
- [ ] Verify accepted-before-provider Turn occupancy, queued message order,
  streaming presentation, response failure ordering, and immediate stop during
  a response. Do not introduce provider cancellation, waiting on stop, a second
  current-Agent/History owner, or a new scheduler abstraction.
- [ ] Update architectural descriptions to the final organization: Commands
  coordinates admission, dispatch, and completion; TuiAdapter realizes terminal
  operations; ConversationInput handles human input; Runtime owns Agent/Turns;
  Tui composes them. Describe the one-call API and Adapter-specific output
  without adding a universal response format or a separate runner.
- [ ] Revise ADR-0006 interface references and the scoped return-contract
  wording in ADR-0007. Preserve deferred selection semantics, technical
  completed/unknown/failed meaning, shared composition, and Host Application
  choice of History. Explicitly document the migration to
  CommandAdapterInterface and Adapter output returned by Commands.run().
- [ ] Full tests and static analysis pass in neuron-interaction and neuron-tui
  with the intended shared dependency integrated. Verify the backend example
  and TUI examples use the revised contract. Record package revisions and
  validation results so the two-package integration is reproducible.
- [ ] Verify behavior through the existing public Tui seam and shared
  Commands.run() protocol tests. Preserve focused tests of existing behavior
  modules. Do not add constructor-wiring assertions, runtime mocks, forwarding
  call-count tests, or duplicate suites for coverage already present.
