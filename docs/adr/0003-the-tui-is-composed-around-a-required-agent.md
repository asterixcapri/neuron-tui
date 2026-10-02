# The TUI owns its Conversation

_2026-10-02 — Fluent composition revision supersedes host-supplied Conversation
composition. The Host Application passes a configured Agent to Tui and supplies
optional dependencies, including SessionStore, through fluent setters
before run. Tui constructs its Conversation once startup begins, after checking
the terminal. An optional initial Session is selected directly; otherwise the
Conversation creates a new Session in the configured Store, or in an independent
in-memory Store when none is supplied. Selecting an initial Session requires an
explicit Store; Session and Store setters may be called in either order before
startup. Configuration creates
no temporary Session. This keeps execution and session-switching operations out
of the host's TUI setup interface without adding a separate configuration object.
Conversation retains its constructor-based composition and operational methods
for other adapters, with a required SessionStore. The revisions below are
historical; turn scheduling, command admission and response-stop behavior are
preserved._

_2026-10-02 — Naming revision: the core class
`NeuronInteraction\Conversation\ConversationRuntime` becomes
`NeuronInteraction\Conversation`. The internal TUI ConversationController becomes
`NeuronTui\Turn\TurnScheduler`, and input interpretation moves to
`NeuronTui\Input\InputHandler`. ConversationView keeps its name.
CommandAdapterInterface retains its Agent and Session controls; optional
AbstractCommandAdapter shares delegation without exposing the Conversation to
Commands. This revision changes names and delegation, preserving FIFO,
preparation, response stop, command admission, History and title generation.
The historical decision below retains its original terminology._

_The native-stream revision supersedes the extraction's core FIFO and custom
EventPublisher protocol. The Host Application still supplies a configured Agent
and constructs `NeuronInteraction\Conversation`; `Tui::make()`
receives that Conversation. Core owns message preparation, Agent/Session binding,
native Neuron output and supported response stop. The user-approved simplification
removes core isBusy() and overlap admission: TUI derives interaction state from
its own preparation, ready stream, running task and pending queue. Hosts coordinate
external executions; core has no session execution lock.
The TUI is the terminal frontend: its internal TurnScheduler owns
pending original inputs, FIFO progression,
Amp scheduling, stream consumption, presentation and Session title scheduling.
This matches a React frontend that queues inputs and submits one streaming HTTP
request at a time. Pending input is prepared when its turn reaches execution
admission. Live input is shown immediately through display projection; rejection reports an
error and restores input only to an empty composer, preserving a newer draft.
submitMessage() is the sole submission API for both human input and Command
prompts. Preparation is internal, and processors preserve recognized expanded
content. forDisplay() projects all user messages, including live input, pending
messages, saved History, and Command-generated prompts. The pending queue retains
the original messages, so display projection does not affect preparation or recall.
Command collections remain client-owned. UI effects belong to the Adapter;
conversation operations delegate to core. Command visibility and admission belong
entirely to the client: TUI filters suggestions and its Adapter refuses ordinary
Commands while busy, including its local turn reservation. Selection continuations
invoke Commands directly with a fresh Adapter. Core has no Command availability
or dispatch methods; it validates Session ownership. TUI requests response stop
only for its own active task; the core writes StopSignal without busy admission. Native generators preserve
Neuron objects and AgentState without a second event vocabulary. History
presentation and display-position correlation belong entirely to the TUI, using
native Neuron messages and ToolCall without core presentation snapshots. Host composition, Session
ownership, captured execution context, first-match identifiers, no default Command
mounting, single-run lifecycle and response-stop limits remain unchanged._

_The Refine Interaction composition revision supersedes the TUI-owned mounting
and rejection of module constructor composition below. The required Agent and
optional Terminal are followed by independently optional Commands, SessionStore
and InputHistory in both the constructor and make factory. Supplied modules are
reused; omitted defaults are created once per TUI instance. Commands owns
mutable addCommand mounting; Tui::addCommand and setStorage are removed.
Commands are configured before run, without collection freezing or live
synchronization. SessionStore binds Storage to a user at construction; Tui
resolves local identity from explicit configuration, operating-system user or a
stable local fallback, and reuses a supplied Store without rebinding it.
Branding, no automatic mounting, first-match duplicates and single-run behavior
remain unchanged._

_ADR-0005 previously superseded History and SessionStore ownership. Its revision
notice now records optional module composition and the Neuron AI 4 revision:
the Host Application selects an initial Session, or Tui creates one in its Store. The historical decision text follows; apply these
scoped supersessions._

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
accepts one command or an array of commands, validates
each value as it is added, preserves order and does not reject duplicate names.
The first command with a repeated name is the one reached; availability and suggestions resolve the
same first entry. A later concurrent duplicate cannot make an unavailable first
entry executable. The terminal Adapter owns busy-state admission and presents
refusal; core ConversationRuntime exposes no busy state. This duplicate rule supersedes the
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
- No Command is mounted automatically. The Host Application adds every Command
  it wants, as established by ADR 0002.
- The Agent passed at construction is the initial Agent. A mounted command may
  still put another Agent in charge through `Controls::useAgent()`.
- Title and subtitle default to `Neuron AI` and `Agent conversation`; setters
  preserve the strings they receive without special empty-value behaviour.
- Mutating configuration after `run()` begins, or running the same instance a
  second time, is a logic error. `run()` blocks until the terminal closes and
  returns nothing; the Agent retains the History.
