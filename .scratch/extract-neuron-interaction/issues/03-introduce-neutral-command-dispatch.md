# 03: Introduce neutral Command dispatch

**What to build:** Give Host Applications one presentation-independent Command collection that accepts neutral identifiers and textual arguments, dispatches deterministically and reports a technical execution outcome.

**Blocked by:** None (can start immediately).

**Status:** done

- [x] Shared Command identifiers contain no slash or other Adapter invocation syntax.
- [x] Neuron TUI continues to accept and suggest slash-prefixed Commands while removing the slash at its boundary.
- [x] Raw argument text is delivered through `CommandArguments` with whitespace behavior covered at the public parsing and dispatch seams.
- [x] `Commands` preserves mounting order and exposes mounted Commands for enumeration.
- [x] The first mounted Command with a duplicate identifier executes, while duplicate entries may remain enumerable.
- [x] Dispatch reports completed, unknown and failed states through one `CommandExecution` type.
- [x] A failed execution retains the identifier and original exception; an unknown identifier is not represented by an exception.
- [x] Command dispatch behavior is tested through the public collection and the TUI public API.
