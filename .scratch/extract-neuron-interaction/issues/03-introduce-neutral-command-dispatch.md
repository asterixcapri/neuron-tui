# 03: Introduce neutral Command dispatch

**What to build:** Give Host Applications one presentation-independent Command collection that accepts neutral identifiers and textual arguments, dispatches deterministically and reports a technical execution outcome.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] Shared Command identifiers contain no slash or other Adapter invocation syntax.
- [ ] Neuron TUI continues to accept and suggest slash-prefixed Commands while removing the slash at its boundary.
- [ ] Raw argument text is delivered through `CommandArguments` with whitespace behavior covered at the public parsing and dispatch seams.
- [ ] `Commands` preserves mounting order and exposes mounted Commands for enumeration.
- [ ] The first mounted Command with a duplicate identifier executes, while duplicate entries may remain enumerable.
- [ ] Dispatch reports completed, unknown and failed states through one `CommandExecution` type.
- [ ] A failed execution retains the identifier and original exception; an unknown identifier is not represented by an exception.
- [ ] Command dispatch behavior is tested through the public collection and the TUI public API.

