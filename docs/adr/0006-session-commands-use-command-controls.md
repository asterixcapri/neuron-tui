# Session commands use Command Controls

_The shared-contract revision replaces `CommandControlsInterface` with
`CommandControlsAdapterInterface`, retaining the control verbs and adding admission
and completion. `Commands::run()` coordinates those phases; ADR-0007 describes
the Adapter's completion output._

`ClearCommand` and `ResumeCommand` belong to Neuron Interaction because they
are the native Command interface to Sessions. Like every Command, they return
no domain-specific result and express their interaction through
`CommandControlsAdapterInterface`.

Clearing starts a Session and may say what changed. Resuming without a key
calls `requestSelection()` with a `SelectionRequest` and then finishes. The
request names the Command to invoke, describes the choice and carries its
`SelectionOption` values, labels and optional descriptions, but retains no
selected value. After collecting a choice, the Adapter invokes `ResumeCommand`
again with the selected value as `CommandArguments`. That invocation installs
the selected History on a fresh Agent and may say what changed.

This two-step exchange lets a TUI use a Picker and a web frontend use a later
HTTP request. Neither presentation mechanism enters the shared module, and
`CommandControlsAdapterInterface` need not retain temporary selection state.

Both Commands build through the supplied AgentFactoryRegistry using the latest
saved global Configuration. The Host Application registers closures that restore
constructor dependencies and setter-driven settings. Clear assigns a newly created
Session; Resume first verifies the selected Session and assigns its History. Neither
Command saves unchanged configuration or recreates the current class implicitly.
A missing Session warns without constructing; factory and preparation failures are
reported through ordinary Command completion before activation. This does not
promise rollback of a Session already created during preparation.

The TUI shares its registry and ConfigurationStore with deferred selection
invocations and reads its live Agent at execution time. A replacement with the
identical History object preserves notices and presentation; a changed History is
rendered anew. Subsequent Turns always use the activated Agent.
