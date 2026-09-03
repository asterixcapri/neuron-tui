# The TUI is composed around a required Agent

Neuron TUI follows Neuron AI's fluent construction style without copying the
Agent's subclass-based configuration model. `NeuronTui\Tui` is final, receives
a ready-to-use concrete `Agent` when it is constructed, offers `make()` as the
documented equivalent of its public constructor, and exposes only
`setTitle()`, `setSubtitle()`, `addCommand()` and `run()`. The Host Application
continues to own provider, tools, middleware, History, Sessions and the
composition of any multi-Agent system. This keeps the TUI a terminal Adapter
rather than a second owner of the Agent.

Configuration is accumulated before `run()`, and the terminal widgets and
listeners are built once inside `run()`. The instance is frozen when that
single run starts. `addCommand()` deliberately follows `Agent::addTool()`: it
accepts one command, a `CommandKit`, or an array containing either, validates
each value as it is added, preserves order and does not reject duplicate names.
The first command with a repeated name is the one reached; repeated entries may
remain visible in command suggestions. This duplicate rule supersedes the
contrary rule in ADR 0002.

## Considered options

- Protected `agent()`, `title()` and `commands()` hooks were rejected. An Agent
  is specialized because its provider, instructions and tools define its
  behaviour; a TUI is composed by the Host Application.
- A constructor-only interface was rejected because every new option would
  widen the constructor and make incremental configuration less idiomatic in
  Neuron AI.
- Accepting `Workflow` or a local conversational interface was rejected for
  now. An arbitrary Workflow does not guarantee messages, streaming semantics
  or History, while Neuron AI 3.15.30's `AgentInterface` does not expose the
  History getter the TUI needs. A multi-Agent system therefore reaches the TUI
  through a coordinating concrete Agent.

## Consequences

- The public entry point is `final class NeuronTui\Tui`; the complete package
  and namespace rename is made without a compatibility layer for the former
  public entry point.
- `Tui::make($agent, $terminal)` and `new Tui($agent, $terminal)` are equivalent,
  while documentation leads with `make()`.
- No command is mounted automatically. The Host Application adds every Slash
  command it wants, as established by ADR 0002.
- The Agent passed at construction is the initial Agent. A mounted command may
  still put another Agent in charge through `Controls::useAgent()`.
- Title and subtitle default to `Neuron AI` and `Agent conversation`; setters
  preserve the strings they receive without special empty-value behaviour.
- Mutating configuration after `run()` begins, or running the same instance a
  second time, is a logic error. `run()` blocks until the terminal closes and
  returns nothing; the Agent retains the History.
