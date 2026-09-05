# Command execution reports technical outcomes

_The shared-contract revision changes the interface and return contract:
`CommandAdapterInterface` replaces `CommandControlsInterface`, and
`Commands::run()` returns Adapter output instead of `CommandExecution`._

Commands return `void`; they use `CommandAdapterInterface` for their visible
effects instead of defining domain-specific result types. `Commands::run()`
coordinates lookup, admission, dispatch and completion in one call. It passes
the technical `CommandExecution` (completed, unknown or failed) to
`afterExecution()` and returns that Adapter's output unchanged. The TUI returns
`null` after updating the view; a backend may return its own response data.
The shared module prescribes no response format or transport dependency.

The dispatcher catches exceptions raised by a mounted Command and includes
the Command identifier and original exception in the failed execution. The
TUI may report the failure and remain usable, while a web backend may log the
exception and choose an appropriate HTTP response without duplicating the
execution guard. An unknown Command is also a technical execution outcome,
not an exception or a semantic Command result.

Unknown identifiers reach completion without admission. A refused Command
returns `null` without dispatch or completion; the Adapter handles refusal
during admission. Only Command exceptions become failed executions: admission
and completion exceptions propagate to the caller. The `completed` outcome
means the Command invocation returned, so pending selections and Agent
responses may finish later, and already performed effects are not rolled back
after a failure.

This keeps the invocation protocol inside `Commands::run()` and the environment's
operations and output inside its Adapter. Callers need no separate runner or
manual completion call. The TUI captures History only after admission, compares
it with the current Agent's History after dispatch, and reconciles a replacement
before reporting any Command failure. An unchanged History preserves notices
and warnings; an Agent replacement alone does not repaint the conversation.
