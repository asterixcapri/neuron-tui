# Command execution across Adapters

Implemented design. This replaces the earlier proposal for a separate or fused
CommandRunner. The [spec](spec.md) records the migration scope; [verification](verification.md)
and [review](review.md) record the completed two-package implementation.

## Caller contract

The caller constructs Commands and calls run() once:

```php
$commands = new Commands([new ResumeCommand(), new HelpCommand()]);
$output = $commands->run($identifier, $arguments, $adapter);
```

There is no separate runner to construct and no manual completion call.
CommandAdapterInterface replaces CommandControlsInterface and contains the
nine existing verbs plus admit(CommandInterface): bool and
afterExecution(CommandExecution): mixed. CommandInterface.run() receives this
single Adapter interface and still returns void.

Commands owns the order; the Adapter realizes environment-specific operations
and produces its output from afterExecution(). PHPDoc generics can describe
concrete Adapter output types without importing transport dependencies.

## Invocation implementation

Illustrative implementation inside Commands; imports and mounting are omitted:

```php
public function run(
    string $identifier,
    CommandArguments $arguments,
    CommandAdapterInterface $adapter,
): mixed {
    $command = $this->named($identifier);

    if ($command === null) {
        return $adapter->afterExecution(
            CommandExecution::unknown($identifier),
        );
    }

    if (!$adapter->admit($command)) {
        return null;
    }

    try {
        $command->run($adapter, $arguments);
        $execution = CommandExecution::completed($identifier);
    } catch (Throwable $exception) {
        $execution = CommandExecution::failed($identifier, $exception);
    }

    return $adapter->afterExecution($execution);
}
```

- Unknown: no admission or Command call; completion receives unknown.
- Refused: admission handles refusal; no Command or completion call; run()
  returns null. Callers of Adapters that can refuse must allow this result.
- Admitted: Command effects happen normally; its failure becomes a technical
  outcome; completion interprets that outcome and returns Adapter output.
- Admission and completion exceptions propagate. Completion is outside the
  Command exception guard and is never retried as a failed Command execution.

## TUI and backend

| Operation | TuiAdapter | Backend Adapter example |
| --- | --- | --- |
| admit | Apply Help/Leave rule, clear admitted input, capture previous History | Return true |
| say / warn | Present through View | Collect response messages |
| agent / useAgent | Delegate to sole current-Agent owner in Runtime | Access/replace request Agent, or delegate to Host's existing owner |
| commands / sessions | Return supplied instances | Return supplied instances |
| promptAgent | Delegate to ordinary Runtime message flow | Delegate to Host Application Agent flow |
| requestSelection | Queue Picker, then invoke through Commands.run() | Collect a SelectionRequest for the frontend |
| stop | Delegate to Runtime | Record the application's interaction-stop instruction |
| afterExecution | Reconcile History, present unknown/failure, return null | Interpret outcome and return response data or a framework response |

```php
// Backend: prepared Agent and fresh Adapter for this request.
$adapter = new BackendAdapter($agent, $commands, $sessions, $submitPrompt);
return $commands->run($identifier, $arguments, $adapter);
```

```php
// TUI: fresh Adapter for this invocation, with live collaborators.
$commands->run(
    $submission->name,
    $submission->arguments,
    new TuiAdapter($runtime, $view, $commands, $sessions),
);
```

The backend output format belongs to its Adapter. JsonResponse is one possible
Host Application type, not a shared dependency. The existing package example
can return plain data and remain framework-independent.

The TUI Adapter retains a nullable previous-History reference per invocation.
Admission sets it immediately before dispatch. Completion compares identities
and repaints before presenting a failure. An unknown Command requires no prior
admission. This baseline does not own the current History: current Agent and
History access always goes through Runtime.

## Effects and later invocations

Output describes what the Adapter produced, not operations Commands must apply
later. say() has no universal payload: the TUI may already have displayed text
while the backend collected it. useAgent() takes effect immediately. A failed
Command does not roll back earlier effects. promptAgent() enters the ordinary
Agent flow; completed does not mean that its response has finished.

```text
TUI
  Commands.run('/resume', empty arguments, fresh Adapter)
    -> Command requests selection; Adapter queues it
    -> Command returns; afterExecution(completed) updates the view
  later: Picker choice
    -> Commands.run(target, chosen value, fresh Adapter)
       -> same admission, dispatch, and completion protocol

Backend
  Commands.run('/resume', empty arguments, request Adapter)
    -> Command requests selection; Adapter collects it
    -> afterExecution(completed) returns a response with the request
  later: frontend submits the choice in a new backend request
    -> Commands.run(target, chosen value, new request Adapter)
```

Cancellation omits the later invocation. Selected arguments remain unchanged
and do not enter human Input history. TUI callbacks retain their request and
original Adapter; the later invocation gets a fresh Adapter with the same live
collaborators. No suspended Command, second Agent owner, or effects queue is
introduced.

## Migration and evidence

This changes the shared public contract: rename the interface, migrate Command
signatures and Adapter implementations, and change run() from returning
CommandExecution to returning Adapter output. Technical outcomes remain
completed/unknown/failed and reach afterExecution(). Update the scoped
ADR-0006/ADR-0007 wording during implementation while retaining selection
semantics and immediate effects.

Migrate neuron-interaction and neuron-tui together, including tests and examples.
Test the shared protocol through Commands.run() and terminal behavior through
Tui with VirtualTerminal, FakeAIProvider, and in-memory Storage. The investigation
ran the existing shared Command suite: 49 tests and 142 assertions passed. The completed implementation and its full validation are recorded in
[verification](verification.md).
