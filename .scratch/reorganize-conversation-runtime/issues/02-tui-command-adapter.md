# 02: Execute every TUI Command through TuiAdapter

**What to build:** Typed Commands and selected Commands both use the revised
Commands.run() lifecycle. TuiAdapter supplies the terminal behavior before,
during, and after dispatch, so users retain composer, History, selection, and
Agent behavior while execution leaves ConversationRuntime. This ticket
integrates the shared contract into neuron-tui; extraction of human input and
startup assembly is completed in ticket 03.

**Blocked by:** 01 — Introduce the Command Adapter execution lifecycle in neuron-interaction.

**Status:** ready-for-agent

- [ ] Update the TUI and its example dependency declarations and lock data to
  consume the revised neuron-interaction implementation from ticket 01.
  Migrate production Commands, example Commands, test fixtures, and shared
  interface references. Use the actual source-package revision, not edits to
  installed vendor files, and record the revision used for verification.
- [ ] Replace CommandControls and its five constructor-injected closures with
  concrete TuiAdapter implementing CommandAdapterInterface. Its collaborators
  are ConversationRuntime, ConversationView, Commands, and Sessions. Do not
  introduce a separate runner, a second controls interface, or a runtime
  interface for mocks.
- [ ] Expose the runtime operations agent(), useAgent(), send(), isBusy(),
  stop(), isStopped(), and tick() as the explicit conversation interface.
  The runtime remains the sole owner of the current answering Agent,
  TurnQueue, response Future, and stopped state; it does not implement the
  Adapter contract. Input handling and startup may remain there until ticket 03.
- [ ] The existing submission path invokes Commands.run() directly with the
  interpreted identifier, arguments, and a fresh TuiAdapter. Remove the old
  runtime admission, dispatch, reconciliation, and selection implementations;
  callers do not perform a separate completion call.
- [ ] TuiAdapter.admit() implements the existing permission rule and visible
  refusal. Only admitted Commands clear the composer and capture the current
  History for comparison. Unknown and refused Commands preserve the composer.
- [ ] Help and Leave are permitted by type during an occupied Turn, including
  their aliases. Unrelated Commands cannot gain permission through their
  names. Suggestions and admission share the existing type predicate while
  preserving first-match dispatch, duplicate rows, and current shadowed
  duplicate behavior. No configurable permission framework is introduced.
- [ ] TuiAdapter.afterExecution() returns null. For admitted successful or
  failed dispatch it reconciles only a change of History identity, then shows
  the reported failure in the existing format. Unknown execution shows the
  existing unknown-Command message without requiring admission or repainting
  History. An unchanged History preserves existing notices and warnings.
- [ ] Each invocation receives a fresh Adapter with readonly collaborators
  and an initially null previous-History comparison reference. That reference
  is set on successful admission and is never a second current-History owner.
  Current Agent and History access reads live runtime state.
- [ ] say() and warn() use the view. promptAgent() constructs MessageForAgent
  and uses the ordinary runtime message flow, without recording human Input
  history. Agent access, replacement, and stopping delegate to the runtime;
  Commands and Sessions return the supplied instances.
- [ ] useAgent() transfers the existing History and makes the replacement
  immediately available through agent(). A Command can then install another
  History on that replacement. An in-flight response keeps the Agent captured
  when responding began, and an accepted message occupies a Turn before the
  provider starts.
- [ ] requestSelection() queues presentation and returns immediately. Its
  callback checks stopped state before showing the Picker, converts the
  options for the view, and presents selection failures in the existing
  visible format. Widget-specific choice and shutdown behavior stays in the view.
- [ ] A chosen value invokes the request's target through Commands.run() with
  unchanged Command arguments and a fresh TuiAdapter sharing the live
  collaborators. It traverses admission and completion again without passing
  through Submission or Input history. Escape, leaving an open Picker, and
  stop before deferred presentation do not invoke the selected Command.
- [ ] Tui integration coverage with VirtualTerminal, FakeAIProvider, and
  in-memory Storage verifies the migrated Command paths, replacement Agent
  History, failure after History change, Help/Leave during a Turn, programmatic
  prompts, and deferred selection. Preserve existing TurnQueue, Submission,
  and AgentTurn tests; add only meaningful uncovered regressions, including
  queued selection followed by stop when not already covered.
- [ ] Relevant TUI regression suites and static analysis pass against the
  revised dependency. Update directly affected Command/Adapter descriptions
  and examples; final input/composition descriptions and full integrated
  verification belong to ticket 03.
