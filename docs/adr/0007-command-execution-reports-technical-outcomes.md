# Command execution reports technical outcomes

Commands return `void`; they use `CommandControlsInterface` for their visible effects
instead of defining domain-specific result types. `Commands::run()` reports
only a `CommandExecution`: completed, unknown or failed.

The dispatcher catches exceptions raised by a mounted Command and includes
the Command identifier and original exception in the failed execution. The
TUI may report the failure and remain usable, while a web backend may log the
exception and choose an appropriate HTTP response without duplicating the
execution guard. An unknown Command is also a technical execution outcome,
not an exception or a semantic Command result.
