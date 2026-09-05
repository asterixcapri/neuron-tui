# 01: Introduce the Command Adapter execution lifecycle in neuron-interaction

**What to build:** A library user constructs Commands and calls run() once to
execute a Command and receive the Adapter's output. The existing backend
example demonstrates this complete path, including a Selection request and
a later invocation with the chosen value. This ticket changes neuron-interaction
and migrates its own Commands, tests, examples, and documentation. The TUI
adopts the revised dependency in ticket 02.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] CommandAdapterInterface replaces CommandControlsInterface. It contains
  the nine existing control verbs plus admit(CommandInterface): bool and
  afterExecution(CommandExecution): mixed. CommandInterface.run() receives
  the new interface and still returns void. There is one shared Adapter
  interface, without an additional controls interface or CommandRunner.
- [ ] Commands retains ordered mounting, exact identifier lookup, and
  first-match duplicate resolution. Its existing run() operation coordinates
  admission, Command invocation, technical outcome capture, and completion.
- [ ] An unknown identifier reaches afterExecution() with an unknown
  CommandExecution, without calling admission or invoking a Command. run()
  returns the Adapter's output unchanged.
- [ ] For a known identifier, admission receives the first matching Command.
  Refusal returns null without Command effects or a completion call. The
  Adapter handles refusal within admit(); no new technical outcome status
  is introduced.
- [ ] An admitted Command runs with the supplied Adapter and unchanged
  Command arguments. Its normal return produces completed; its exception
  produces failed with the original exception. Both outcomes reach
  afterExecution(), whose output is returned unchanged.
- [ ] The exception guard surrounds the Command invocation. Exceptions from
  admit() and afterExecution() propagate unchanged; a completion failure is
  neither converted into a Command failure nor followed by another completion
  call. Already performed Command effects are not rolled back.
- [ ] CommandExecution remains a technical completed/unknown/failed outcome,
  rather than the public return value of run(). Completion does not mean a
  requested selection or Agent response has finished. Adapter output may have
  any environment-specific type, and run() also permits null on refusal.
  Add PHPDoc generics where required for useful static typing.
- [ ] Migrate every shared Command and existing test Adapter to the new
  interface while preserving all nine verbs, immediate Agent replacement,
  History transfer, mounted module access, and existing Command behavior.
- [ ] The backend example admits its Commands, collects notices, warnings,
  Selection requests and stop effects, and returns its response data from
  afterExecution(). Its caller obtains the response with run() alone, with
  no manual completion or output-collection step.
- [ ] The backend example and its coverage demonstrate two independent
  invocations for selection: the first returns a serializable Selection
  request; the second uses the requested identifier and unchanged chosen
  value with fresh request state. Cancellation omits the second invocation.
  Generated prompts and selection continuations do not add human Input
  history entries.
- [ ] The Host Application continues to supply Agent execution. No HTTP
  framework, universal response payload, deferred-effects interpreter,
  provider scheduler, or TUI permission policy is added to the shared package.
- [ ] Update shared usage documentation to describe the one-call lifecycle,
  Adapter-specific output, refusal result, exception boundaries, and interface
  migration. Tests exercise the public protocol with concrete Adapters and
  observable output, rather than private wiring.
- [ ] Full neuron-interaction tests and static analysis pass. Record the
  implementation revision and validation results for ticket 02. Implement in
  the source package rather than modifying installed vendor code; leave the
  TUI's existing dependency revision unchanged in this ticket.
