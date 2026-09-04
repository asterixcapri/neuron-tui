# 05: Make Command selection non-blocking

**What to build:** Allow a Command to request a human selection and finish, then continue through a new invocation carrying the selected value, while retaining the terminal Picker experience.

**Blocked by:** 04: Replace Controls with Adapter-owned Command Controls.

**Status:** done

- [x] A `SelectionRequest` identifies the Command to invoke, supplies its prompt and carries ordered Selection options.
- [x] Each `SelectionOption` has a stable value, visible label and optional description.
- [x] Requesting a selection returns immediately and retains no selected value in Command Controls.
- [x] The Adapter invokes the named Command again with the selected value as new `CommandArguments`.
- [x] Resume without a Session key emits a Selection request and performs no resume in that invocation.
- [x] Resume with a selected key installs the corresponding Session History on the answering Agent.
- [x] Neuron TUI presents the request through its Picker and routes selection or cancellation without blocking shared Command code.
- [x] The two-invocation flow is tested through fake Command Controls and the virtual terminal.

## Comments

Removed the temporary `choose()` bridge and migrated Resume, the Model example,
and Command-based Picker tests. `SelectionRequest::description` preserves the
existing optional Picker introduction. Cancellation ends the Picker without a
new invocation; reopening after cancellation is an explicit new submission.
Session recognition descriptions now live in `Command/SessionMetadata.php`,
without terminal dependencies.

The user requested an `Interface` suffix for every interface. The controls
contract is therefore `CommandControlsInterface`; code, tests and the example
use that spelling.
