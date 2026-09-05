# Session commands use Command Controls

`ClearCommand` and `ResumeCommand` belong to Neuron Interaction because they
are the native Command interface to Sessions. Like every Command, they return
no domain-specific result and express their interaction through
`CommandControlsInterface`.

Clearing starts a Session and may say what changed. Resuming without a key
calls `requestSelection()` with a `SelectionRequest` and then finishes. The
request names the Command to invoke, describes the choice and carries its
`SelectionOption` values, labels and optional descriptions, but retains no
selected value. After collecting a choice, the Adapter invokes `ResumeCommand`
again with the selected value as `CommandArguments`. That invocation installs
the selected History on the Agent and may say what changed.

This two-step exchange lets a TUI use a Picker and a web frontend use a later
HTTP request. Neither presentation mechanism enters the shared module, and
`CommandControlsInterface` need not retain temporary selection state.
