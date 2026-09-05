# Unify Command execution through Commands and explicit Adapters

Status: ready-for-agent

## Problem Statement

Maintaining the Conversation TUI requires following responsibilities spread
between ConversationRuntime and CommandControls. The runtime owns the live
Agent and Turn lifecycle, but also interprets terminal input, navigates Input
history, admits and executes Commands, reconciles History, presents deferred
selections, and assembles the terminal. CommandControls receives five closures
that expose fragments of this implementation instead of explicit collaborators.

This makes changes to one interaction path difficult to assess: a selection
must use the same Command execution rules as typed input, programmatic prompts
must share the ordinary Turn flow without becoming Input history, and Agent
replacement must remain immediately visible without duplicating current state.
Moving all operations onto the runtime or moving conversation state into the
controls would blur the responsibilities further.

## Solution

Keep ConversationRuntime responsible for the conversation in progress and
extract terminal input handling into ConversationInput. Make the existing
Commands.run() in neuron-interaction own the complete invocation protocol:
lookup, Adapter admission, Command dispatch, technical outcome capture, and
Adapter completion. Callers construct Commands and call run(); they do not
need a separate runner or a second call to finish the invocation.

Replace CommandControlsInterface with one CommandAdapterInterface containing
the nine existing control verbs, admit(), and afterExecution(). TuiAdapter
implements the terminal-specific operations and replaces CommandControls.
Commands.run() calls afterExecution() and returns its output unchanged. A
backend Adapter can return its response; the TUI updates the view and returns
null. No universal response payload or HTTP dependency enters the shared module.

Preserve the public Tui composition contract and observable terminal behavior.
The scope now includes an intentional shared-contract revision in
neuron-interaction: the interface name and lifecycle change, and run() returns
Adapter output instead of CommandExecution. Update both packages and their
examples consistently. This supersedes the earlier TUI-only refactor scope.
Conversation state still has one owner, without an event bus or new runtime
abstraction for mocking.

## User Stories

1. As a maintainer, I want ConversationRuntime to own the current Agent and Turn lifecycle, so that conversation transitions have one authoritative implementation.
2. As a maintainer, I want terminal input rules outside the runtime, so that keyboard changes do not require understanding asynchronous response handling.
3. As a maintainer, I want one complete TUI Command execution path, so that typed Commands and selected Commands receive the same guarantees.
4. As a maintainer, I want Commands to coordinate invocation and the Adapter to implement environment-specific operations, so that the protocol does not spread across callers or closure bindings.
5. As a Command author, I want the existing nine control verbs available through CommandAdapterInterface, so that migrating the interface preserves what my Command does.
6. As a Command author, I want agent() to return the current Agent immediately after useAgent(), so that subsequent operations affect the replacement.
7. As a TUI user, I want replacing the answering Agent to preserve the same History, so that my conversation continues with the next Agent.
8. As a Command author, I want to install another History on the replacement Agent, so that a Command can deliberately start a different conversation.
9. As a TUI user, I want accepted messages to occupy a Turn before the provider starts, so that rapidly submitted messages retain their ordering.
10. As a TUI user, I want queued messages answered in order, so that the refactor does not change conversation progression.
11. As a Command author, I want programmatic prompts to use the ordinary message flow, so that queueing and presentation remain consistent.
12. As a TUI user, I want only actual submitted human input recorded in Input history, so that generated prompts and Picker choices do not pollute recall.
13. As a TUI user, I want unknown and refused Commands to preserve the composer, so that I can correct or retry them.
14. As a TUI user, I want accepted Commands to clear the composer, so that execution retains its current interaction behavior.
15. As a TUI user, I want Help and Leave available during a Turn, including their aliases, so that I can inspect available Commands or exit while waiting.
16. As a maintainer, I want suggestions and execution to share the existing type-based concurrent Command rule, so that the permission policy has one definition.
17. As a TUI user, I want other Commands refused during a Turn regardless of their names, so that aliases cannot bypass the existing policy.
18. As a Command author, I want requestSelection() to return before a person chooses, so that selection remains a later Command invocation.
19. As a TUI user, I want a chosen value forwarded unchanged as Command arguments, so that presentation does not alter the requested operation.
20. As a TUI user, I want cancelling or leaving a Picker to avoid reinvocation, so that abandoned choices have no subsequent Command effect.
21. As a TUI user, I want leaving during an Agent response to remain immediate, so that closing the terminal does not introduce a wait for the provider.
22. As a TUI user, I want failures after a History change shown on the resulting conversation, so that the error is not erased by reconciliation.
23. As a TUI user, I want unchanged History to avoid unnecessary repainting, so that notices and warnings remain visible.
24. As a TUI user, I want input recall, scrolling, suggestion keys, and Picker precedence preserved, so that familiar keyboard interactions remain reliable.
25. As a Host Application developer, I want supplied Commands, Sessions, and Input history reused, so that the refactor does not reset or copy my configured modules.
26. As a Host Application developer, I want the initial Agent History and optional persistence configuration preserved, so that startup does not create or import a Session implicitly.
27. As a Host Application developer, I want configuration and single-run behavior preserved, so that the internal refactor does not change how I start the TUI.
28. As a maintainer, I want construction without initialization setters or partially constructed objects, so that deferred execution cannot observe incomplete dependencies.
29. As a maintainer, I want existing behavioral tests to verify the reorganization, so that tests survive changes to internal object wiring.
30. As a maintainer, I want architectural descriptions to match final responsibilities, so that later changes do not restore the original coupling.
31. As a library user, I want to construct Commands and call run() once, so that I do not need another runner or a manual completion call.
32. As an Adapter author, I want afterExecution() to receive the technical outcome and produce my environment's output, so that a backend response and terminal effects use the same invocation protocol.
33. As an Adapter author, I want admission and completion failures to remain distinct from Command failures, so that errors in my Adapter are not reported as failures of the invoked Command.

## Implementation Decisions

- Retain ConversationRuntime as the sole owner of the current answering Agent,
  TurnQueue, response Future, and stopped state. History remains reachable
  through the current Agent; do not create a second mutable current-History
  property or introduce a separate Conversation state holder.
- Preserve TurnQueue, AgentTurn, Submission, and ConversationView as concrete
  behavior modules. TurnQueue remains independent of terminal and provider;
  AgentTurn continues to handle one response and its streaming presentation.
- Give the runtime an explicit internal interface: agent(), useAgent(Agent),
  send(MessageForAgent), isBusy(), stop(), isStopped(), and tick(). Keep Turn
  preparation, completion, queue display, and response error handling behind
  this interface. The runtime does not implement CommandAdapterInterface and
  does not expose Command-specific presentation or selection operations.
- Preserve immediate Agent replacement and transfer of the existing History
  before replacement. Read and capture the Agent when an accepted Turn begins
  responding, so an in-flight response keeps its original Agent.
- Preserve occupancy from message acceptance, not merely from Future creation.
  Keep the existing queue, indicator, message display, response completion, and
  failure ordering. Do not add cancellation or waiting to stop().
- Introduce ConversationInput for submission, draft-change handling, recall,
  scrolling, quit keys, and the relevant InputEvent propagation rules. It uses
  the view, InputHistory, runtime, Commands, and Sessions. Keep draft changes,
  navigation abandonment, recording, and submission interpretation together.
- Record nonblank human submissions before their Command admission, including
  unknown or refused Commands as currently supported. Preserve the existing
  blank-input behavior and stopped-input handling. Route messages to the
  runtime. For CommandInput, call Commands.run() with its identifier and
  arguments and a fresh TuiAdapter using the same runtime, view, Commands,
  and Sessions.
- Preserve existing listener priorities and keyboard ownership among Picker,
  Command suggestions, recall, and composer. Retain widget-specific suggestion
  and Picker behavior in the view rather than rewriting it during extraction.
- Extend the existing Commands.run(identifier, arguments, adapter) operation
  in neuron-interaction. Commands retains mounting, collection, first-match
  resolution, and dispatch. Do not add a CommandRunner class or a wrapper that
  requires callers to coordinate the protocol themselves.
- Replace CommandControlsInterface with CommandAdapterInterface, not a second
  interface extending it. The contract contains the existing nine verbs plus
  admit(CommandInterface): bool and afterExecution(CommandExecution): mixed.
  CommandInterface.run() receives CommandAdapterInterface and still returns
  void. Migrate implementations, Command signatures, tests, examples, and
  documentation in both packages to the new contract.
- For an unknown identifier, Commands.run() calls afterExecution() with an
  unknown CommandExecution and returns the Adapter output. It does not call
  admit() or invoke a Command in this case.
- For a known Command, Commands.run() calls admit() on the resolved Command.
  If it returns false, run() returns null without invoking the Command or
  calling afterExecution(). The Adapter handles any visible refusal in
  admit(). Refusal does not introduce another technical CommandExecution
  status. A caller using an Adapter that can refuse must allow a null result.
- If admitted, invoke Command.run(adapter, arguments), capturing only its
  exceptions into a failed CommandExecution; otherwise produce completed.
  Then call afterExecution(execution) outside that exception guard and return
  its output unchanged. Exceptions from admit() or afterExecution() propagate
  to the caller rather than becoming failed Command executions.
- Preserve CommandExecution as the technical completed/unknown/failed outcome
  passed to the Adapter. It does not become a domain result or deferred-effects
  payload. completed means the invocation returned, not that a selection or
  Agent response has finished. Already performed effects are not rolled back
  if the Command fails.
- The Adapter owns its output type. A backend may return a framework response
  or other data; TuiAdapter.afterExecution() returns null after updating the
  view. Keep transport types and response formats out of neuron-interaction.
  Model the Adapter output with PHPDoc generics where needed for static
  analysis; native return types must allow the concrete output and run()'s
  null result on refusal.
- Implement TuiAdapter.admit() with the current TUI permission rule, visible
  refusal, composer clearing, and capture of the History before dispatch.
  Clear the composer and capture History only after admission succeeds.
  Unknown and refused Commands preserve the composer.
- Keep the concurrent Command rule in the TUI and share its predicate between
  admission and suggestions. HelpCommand and LeaveCommand are allowed by type,
  including aliases; unrelated Commands do not gain permission from a name.
  Preserve existing duplicate behavior; this work does not change how
  shadowed duplicate suggestions behave.
  Do not introduce a configurable policy system or a new concurrent interface.
- TuiAdapter.afterExecution() compares the captured History identity with the
  current Agent's History. Reconcile after successful or failed dispatch,
  then present any failure in the existing format. For unknown execution,
  show the existing unknown-Command message without requiring an earlier
  admission call or repainting the History. Do not repaint merely because
  the Agent changed or detect in-place History edits beyond current behavior.
- Remove the separate CommandControls class and its five closure dependencies.
  TuiAdapter is the concrete implementation of CommandAdapterInterface and
  depends on the runtime, view, Commands, and Sessions. Commands depend on
  the shared interface rather than the concrete TUI Adapter.
- Implement TuiAdapter.say() and warn() using the view; implement promptAgent() by
  constructing MessageForAgent and using runtime.send(); delegate current-Agent
  access, Agent replacement, and stopping to the runtime. Return the supplied
  Commands and Sessions instances without reconstructing them.
- Implement requestSelection() directly in TuiAdapter. Schedule the
  Picker through the event loop, return immediately, check stopped state before
  presentation, convert Selection options for the view, and handle selection
  failures with the existing visible error format.
- Reinvoke the selected Command through Commands.run(), using the request's
  identifier, unchanged selected value as Command arguments, and a fresh
  TuiAdapter. This traverses the same admission and completion protocol as a
  typed Command. Do not route the choice through Submission or Input history.
  Cancellation and leaving the Picker must not invoke the target Command.
  Preserve the view's deferred-choice completion and terminal shutdown behavior.
- Construct one TuiAdapter per invocation, including deferred reinvocations.
  Its collaborators are readonly. The optional previous-History reference is
  an invocation-specific comparison baseline, not a second current-History
  owner. Initialize it to null and set it only after admission succeeds.
  Current Agent and History access always reads live runtime state. Selection
  callbacks retain their own request and Adapter; the later invocation gets
  a fresh Adapter with the same live collaborators. No initialization setters,
  dependency lookup container, or Adapter factory module is needed.
  The runtime does not depend on Commands, Sessions, InputHistory, or TuiAdapter.
- Move assembly and view startup to Tui.run(). Construct the view, runtime,
  and input handling there; connect listeners and runtime ticks before
  starting the view. Preserve interactive-TTY validation, propagation of startup
  failures, pre-run configuration, deferred widget construction, and single-run
  semantics. Do not add another launcher or factory solely for delegation.
- Reuse Host Application modules and defaults under the existing composition
  contract. Preserve branding, optional Terminal behavior, initial History,
  no automatic Command mounting, and independently configured persistence.
- Keep TUI implementation modules internal and concrete. Existing substitutable
  terminal, provider, and Storage dependencies remain the testing seams; do not
  add a runtime PHP interface simply to support mocks. CommandAdapterInterface
  is the shared contract for actual interaction Adapters and follows the
  repository interface naming rule.
- Update the existing backend example to implement the same shared contract:
  admit() returns true, the existing verbs collect output or perform their
  effects, and afterExecution() returns the example's response data. Its caller
  uses only Commands.run() to obtain the completed output. A framework-specific
  JsonResponse was an illustration, not a required dependency or response shape.
  Agent prompting remains a Host Application callback; no HTTP framework,
  scheduler, or provider execution engine is introduced in neuron-interaction.
- Align existing responsibility descriptions with the final organization.
  Preserve shared composition, selection semantics, technical outcome meaning,
  and Host Application History choice. During implementation, revise the
  interface references in ADR-0006 and the scoped return-contract wording in
  ADR-0007: CommandExecution is delivered to afterExecution(), while run()
  returns Adapter output. Record this as the agreed shared-contract revision,
  not as preservation of the old public signature and return value.

## Testing Decisions

- Confirmed primary seam: exercise the public Tui
  entry point with VirtualTerminal, FakeAIProvider, and in-memory Storage. This
  is the highest existing seam that covers input, shared Commands, Adapter, runtime,
  rendering, and asynchronous selection together.
- Good tests assert visible terminal outcomes, provider interactions, History
  identity/content where contractually relevant, and Input history entries.
  They do not assert collaborator call counts, constructor wiring, private
  state, or the existence of a particular internal forwarding method.
- Preserve the existing behavioral coverage for Tui, session composition,
  duplicate Commands, and Input history. Preserve the focused tests of
  Submission, TurnQueue, and AgentTurn; their existing seams remain useful.
- Use the existing tests of selection returning before a choice, replacement
  Agent History, failures after History changes, Help/Leave during a Turn,
  abandoned Pickers, and input recall as prior art and regression coverage.
- Verify a command can useAgent() and immediately agent() to change the
  replacement's History; ordinary replacement carries the previous History
  without unnecessary repainting.
- Verify accepted-before-start Turn occupancy, message queue order, streaming,
  and stop during response. Retain existing asynchronous test support rather
  than inventing a scheduler abstraction for the refactor.
- Verify selection returns before presentation completes, invokes the same
  admission/reconciliation protocol later, preserves selected arguments, and
  does not add an Input history entry. Verify cancellation and exit do not
  invoke the selected Command.
- Verify successive Command invocations and deferred reinvocations use live
  Agent state and preserve their own arguments and History reconciliation.
  Assert observable outcomes rather than the identity or allocation of the
  object implementing CommandAdapterInterface.
- Verify failure after changing History leaves the error on the new
  conversation, while unchanged History preserves notices and warnings.
- Verify unknown/refused Commands preserve the composer, accepted Commands
  clear it, and Help/Leave admission and suggestions retain the existing
  type-based rule and duplicate behavior.
- Verify input-history recording and navigation, draft changes, listener
  precedence, Picker ownership, scroll, startup History, supplied module
  identity, TTY rejection, startup failures, and single-run behavior remain
  unchanged through the existing regression suite.
- Add focused regression cases only for meaningful uncovered behavior affected
  by the move, such as a queued selection followed by stop before presentation.
  Do not add mock-based runtime/Adapter suites that duplicate integration
  coverage. If equivalent coverage is moved, replace rather than layer it.
- Exercise the shared Commands.run() protocol with concrete test Adapters:
  unknown execution reaches completion without admission; refusal returns null
  without Command effects or completion; admitted invocations expose their
  completed/failed technical outcome to the Adapter and return its output
  unchanged. Verify admission is applied to the first matching Command.
- Verify exceptions raised by a Command are captured, while exceptions from
  admission or completion propagate unchanged. A completion failure must not
  trigger another completion call or be mistaken for a Command failure.
  Assert the public protocol and resulting output, not private wiring.
- Preserve shared Command and selection behavior through the renamed contract.
  The backend example must complete selection across separate invocations
  using only run() to obtain each response, and forward generated prompts
  without adding human Input history entries.
- Run static analysis and relevant tests in both neuron-interaction and
  neuron-tui during implementation, then their full suites with the revised
  shared dependency integrated. The investigation ran the existing shared
  Command suite successfully (49 tests, 142 assertions); it did not test the
  proposed implementation or run the full TUI suite.

## Out of Scope

- Changing the public Tui configuration or control-verb behavior beyond the
  explicitly selected shared Adapter lifecycle and return-contract revision.
- Introducing a second Conversation owner, controls-owned Agent state, a
  runtime implementation of CommandAdapterInterface, or references that must
  be manually synchronized after Agent replacement.
- Replacing explicit runtime operations with generic intents, an event bus,
  an effects queue, or a snapshot/status protocol.
- Removing event-loop callbacks or pursuing zero closures throughout the TUI.
- Changing provider cancellation, asynchronous execution semantics, concurrent
  Command permissions, selection semantics, or History reconciliation rules.
- New persistence formats, Session migration, automatic Session selection,
  automatic mounting, or live synchronization of mounted Commands.
- Rewriting Picker, composer, suggestions, streaming, or TurnQueue internals.
- A new frontend Adapter, remote runtime, generic dependency-injection system,
  configurable policy framework, or new interfaces solely for mocking.
- A separate CommandRunner, a second controls interface, a universal response
  payload, or Commands that return instructions for the Adapter to execute later.
- Broad cleanup, unrelated documentation changes, and a new test framework.
- Implementation tickets and code implementation; these require their own
  requested transition after this specification.

## Further Notes

The selected design has one invocation entry point, Commands.run(). Commands
owns the order; Adapters own the environment-specific operations before,
during, and after dispatch. A separate runner or a caller-managed
afterExecution() call would expose coordination that run() should hide.

One shared Adapter interface intentionally serves both Commands and the
invoked Command. The Command can see lifecycle methods intended for the
dispatcher; accepting that wider interface avoids another contract solely to
separate callers. afterExecution() names a lifecycle phase rather than a
particular rendering mechanism and works for both terminal effects and backend
responses. It does not complete pending Agent responses or human choices.

This is broader than the original internal refactor: renaming the shared
interface, adding lifecycle methods, and changing the return value of run()
require coordinated migration of neuron-interaction and neuron-tui. Shared
execution tests and the existing backend example verify the cross-Adapter
contract; the public Tui seam verifies terminal behavior. Do not edit installed
vendor sources as the implementation of the shared-package change.

Implement the shared contract and migrate its consumers as a coordinated
change, then finish TUI input extraction, composition, and responsibility
descriptions. Preserve green checks at integrated milestones. This is guidance
for implementation planning, not a ticket decomposition. No production code
changes or implementation tickets have been created.
