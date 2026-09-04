# 05: Make Command selection non-blocking

**What to build:** Allow a Command to request a human selection and finish, then continue through a new invocation carrying the selected value, while retaining the terminal Picker experience.

**Blocked by:** 04: Replace Controls with Adapter-owned Command Controls.

**Status:** ready-for-agent

- [ ] A `SelectionRequest` identifies the Command to invoke, supplies its prompt and carries ordered Selection options.
- [ ] Each `SelectionOption` has a stable value, visible label and optional description.
- [ ] Requesting a selection returns immediately and retains no selected value in Command Controls.
- [ ] The Adapter invokes the named Command again with the selected value as new `CommandArguments`.
- [ ] Resume without a Session key emits a Selection request and performs no resume in that invocation.
- [ ] Resume with a selected key installs the corresponding Session History on the answering Agent.
- [ ] Neuron TUI presents the request through its Picker and routes selection or cancellation without blocking shared Command code.
- [ ] The two-invocation flow is tested through fake Command Controls and the virtual terminal.

