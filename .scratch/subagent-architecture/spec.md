# Subagent concorrenti nella conversazione principale

Status: ready-for-agent

## Problem Statement

Una persona che conversa con l'Agent principale deve poter affidare lavoro a
un Subagent senza bloccare la conversazione fino al termine. Dopo l'avvio deve
poter continuare a scrivere all'Agent principale, chiedere lo stato del
Subagent e inviargli altri messaggi. Quando il Subagent risponde, l'Agent
principale deve ricevere automaticamente quella risposta e poter proseguire lo
scambio usando la stessa identità e la stessa History del Subagent.

Un normale tool Neuron non basta a soddisfare questo comportamento: un tool
restituisce una volta sola e il Turn che lo esegue resta occupato finché non
ritorna. Tenere aperto quel tool impedirebbe alla conversazione principale di
continuare; farlo ritornare subito, invece, richiede un percorso esplicito e
sicuro per consegnare successivamente la Reply del Subagent.

La soluzione deve mantenere la Conversation TUI un Adapter. La composizione
dell'Agent e della capacità multi-Agent resta responsabilità dell'Host
Application, mentre processi, scheduling e lifecycle non devono trapelare nei
tool usati dal modello.

## Solution

L'Host Application aggiunge al proprio Agent un `SubagentToolkit`, configurato
con la classe autoloadabile dell'Agent da usare come Subagent. Il toolkit offre
tre tool al modello:

- `subagent`, che crea un Subagent, avvia il primo Turn in background e
  restituisce subito un ID;
- `subagent_send`, che accoda un messaggio per un Subagent esistente;
- `subagent_status`, che legge stato e History già disponibili nel runtime.

Il Subagent è un'identità conversazionale stabile, con ID, configurazione,
History e coda propri. Non coincide con un processo PHP. Ogni suo Turn viene
eseguito separatamente da un worker del pool; al termine, risposta e nuova
History tornano al runtime e il worker viene liberato. Un Turn successivo può
essere eseguito da un altro worker senza cambiare l'identità del Subagent.

Il tool di avvio non resta sospeso. Quando un Turn figlio termina, il modulo
`Subagents` produce una `SubagentReply` e la invia al `ConversationPort` della
Session principale. La Reply entra nella stessa coda ordinata degli altri
input. Se l'Agent principale è libero, comincia un nuovo Turn; se sta già
rispondendo, la Reply aspetta. La persona vede la risposta con cui l'Agent
principale interpreta il risultato, non un secondo interlocutore che scrive
direttamente nella conversazione.

## User Stories

1. As a person using the Conversation TUI, I want the Agent to delegate a task
   to a Subagent, so that independent work can continue in parallel.
2. As a person using the Conversation TUI, I want the main conversation to
   become available after the starting tool returns, so that I am not blocked
   for the duration of the delegated work.
3. As a person using the Conversation TUI, I want the Agent to tell me the ID
   of the new Subagent, so that later actions can address it unambiguously.
4. As the Agent in charge, I want to start a Subagent with one tool call, so
   that delegation has a small and clear interface.
5. As the Agent in charge, I want to send another message to an existing
   Subagent, so that I can clarify or extend its work.
6. As the Agent in charge, I want a message sent while the Subagent is working
   to be queued, so that the active Turn is not mutated midway through.
7. As the Agent in charge, I want queued messages processed in FIFO order, so
   that the Subagent receives instructions in a predictable sequence.
8. As the Agent in charge, I want each Subagent Reply delivered automatically,
   so that I do not have to poll to discover completion.
9. As the Agent in charge, I want a Reply to identify its Subagent, so that I
   can continue the correct exchange.
10. As the Agent in charge, I want a Reply arriving while I am busy to wait in
    my Turn queue, so that only one writer changes my History at a time.
11. As the Agent in charge, I want person inputs and Subagent Replies ordered
    by acceptance, so that their processing order is deterministic.
12. As the Agent in charge, I want a Subagent's response to use the same
    mechanism whether it contains a result, a question or a request, so that I
    do not need several child-to-parent protocols.
13. As the Agent in charge, I want to inspect a Subagent's status without
    invoking another model, so that status checks are quick and deterministic.
14. As the Agent in charge, I want status to expose the completed History
    rather than a duplicated `last_reply`, so that there is one source of truth.
15. As the Agent in charge, I want status during an active Turn to distinguish
    completed History from work still in progress, so that it does not invent
    partial model state.
16. As the Agent in charge, I want to continue an idle Subagent using its
    existing History, so that follow-up work keeps the prior context.
17. As the Agent in charge, I want an unknown or expired Subagent ID rejected
    clearly, so that I can create a new Subagent or report the problem.
18. As the Agent in charge, I want a failed Subagent marked terminal, so that I
    do not unknowingly append work to a broken execution context.
19. As a person using the Conversation TUI, I want Subagent Replies interpreted
    by the main Agent, so that the conversation retains one Agent in charge.
20. As a person using the Conversation TUI, I do not want a Subagent Reply
    displayed as something I wrote, so that authorship in the conversation is
    truthful.
21. As a person using the Conversation TUI, I want to ask the main Agent for
    Subagent status when I care about it, so that the interface is not flooded
    with intermediate activity.
22. As a person using the Conversation TUI, I want only completed Replies to
    wake the main Agent, so that tool calls, token fragments and file reads do
    not produce unsolicited Turns.
23. As a Host Application author, I want to opt into Subagents by mounting a
    normal Neuron toolkit, so that the Conversation TUI does not compose my
    Agent on its own.
24. As a Host Application author, I want to supply an Agent class for child
    work, so that provider, instructions and tools remain under Host control.
25. As a Host Application author, I want that class instantiated in the worker
    through Neuron's normal construction convention, so that no serializable
    closure or custom public factory is required.
26. As a Host Application author, I want several Subagents to execute
    concurrently up to a bounded limit, so that parallel work does not consume
    unbounded resources.
27. As a Host Application author, I want excess child Turns reported as queued,
    so that capacity limits remain visible and predictable.
28. As a Host Application author, I want idle Subagents not to retain dedicated
    workers, so that the concurrency limit applies to active work rather than
    the number of remembered conversations.
29. As a Host Application author, I want Subagent state scoped to the current
    TUI process and parent Session, so that the first version requires no new
    persistence model.
30. As a person changing Session, I want all Subagents from the previous
    Session cancelled and forgotten, so that late Replies cannot enter the new
    conversation.
31. As a person closing the TUI, I want child executions cancelled, so that the
    application does not leave work behind unexpectedly.
32. As a maintainer, I want the process pool hidden behind the `Subagents`
    module, so that execution technology is not part of the domain model.
33. As a maintainer, I want the TUI event loop to remain responsive while child
    work runs, so that painting and keyboard input continue normally.
34. As a maintainer, I want worker failures translated into domain outcomes, so
    that transport exceptions do not leak into the conversation.
35. As a maintainer, I want the parent and child Histories transferred without
    serializing Agent instances or tool callbacks, so that closures and secrets
    do not cross the process seam.
36. As a maintainer, I want Subagent behaviour testable with fake Agents and
    executors, so that the suite requires no external API key.

## Implementation Decisions

- The canonical entity is **Subagent**. It is not called Delegation, worker,
  task or tool. Its stable state consists of an opaque runtime ID, the child
  Agent class, a separate History, lifecycle state, a FIFO Turn queue, its
  owning parent Session and the current execution when one exists.
- The lifecycle states in the first version are `queued`, `running`, `idle` and
  `failed`. A completed Reply ends the current child Turn and returns the
  Subagent to `idle`; it does not end the Subagent identity.
- The public model-facing interface consists of exactly three tools:
  `subagent(task)`, `subagent_send(subagent_id, message)` and
  `subagent_status(subagent_id)`.
- `subagent` registers the Subagent and schedules its first Turn before
  returning its ID and current state. It does not await the child Reply.
- `subagent_send` accepts a message for an `idle`, `queued` or `running`
  Subagent. An idle Subagent starts a Turn when capacity is available; a busy
  Subagent stores the message in its FIFO queue for a later Turn.
- `subagent_status` is a read of the in-memory registry. It does not call a
  model. It reports the ID, lifecycle state, elapsed time when applicable,
  queued-message count and completed child History. It does not maintain or
  return a separately stored `last_reply` field.
- The child History is the source of truth for completed exchanges. While a
  Turn is running, status makes no claim about uncompleted reasoning, partial
  text or tool activity.
- The Host Application mounts one normal Neuron `SubagentToolkit` on the Agent
  it supplied. This preserves the decisions that the Conversation TUI mounts
  nothing by itself and that the Host owns multi-Agent composition.
- The toolkit receives a `class-string<Agent>` and an optional concurrency
  limit whose default is four. The class must be Composer-autoloadable and
  constructible through `::make()` without arguments inside a worker.
- Provider, instructions, middleware and tools of the Subagent are defined by
  that Agent class. A public factory interface, closure-based factory and
  general-purpose serialized configuration descriptor are intentionally not
  introduced in the first version.
- Credentials must be available to the worker at process creation. Host code
  must not rely solely on mutations to the parent's `$_ENV`; it may use actual
  process environment variables or perform its own worker-visible bootstrap.
- A Subagent is stable across Turns, but no process is dedicated to it. Every
  child Turn is one `amphp/parallel` execution. The execution receives the
  Agent class, message and serialized History, constructs a fresh Agent, and
  returns the completed Reply and updated serialized History. The worker then
  returns to the pool.
- Only ID, Agent class, completed History and queued messages persist between
  child Turns. Mutable state held solely inside an Agent object, provider or
  tool instance is not preserved. State requiring longer lifetime belongs in
  the History or in a dependency external to the worker.
- Histories cross the process seam through Neuron's JSON-compatible message
  representation, not as live Agent, History or Tool objects. This prevents
  closure-backed tool callables and provider credentials from being serialized.
- `amphp/parallel` is a runtime dependency and an execution Adapter beneath the
  `Subagents` module. It is not exposed through the tools or the conversation
  interface.
- The default maximum is four concurrent child Turns across all Subagents.
  Additional Turns wait in `queued`; idle Subagent identities consume no worker
  capacity.
- The first version does not steer an already-running child inference.
  `subagent_send` received during `running` queues the message for the next
  Turn. Therefore semantic communication does not require a bespoke
  bidirectional Channel protocol.
- Waiting for a child execution happens in a background Amp fiber owned by the
  `Subagents` module. The Conversation TUI tick never performs a blocking
  Channel receive or drains worker messages.
- The new conversation seam is `ConversationSource` connected to a
  Session-scoped `ConversationPort`. A model tool capable of producing a later
  Reply implements the source interface. `AgentTurn` connects it to the current
  port after observing its `ToolCallChunk` and before Neuron executes it.
- `connect` only supplies the return address for future Replies. The port does
  not own Subagent IDs, Histories, queues, processes or rendering.
- `ConversationPort` accepts a complete `SubagentReply` and exposes
  cancellation tied to the current parent Session. The `Subagents` module owns
  lifecycle and observes that cancellation.
- `SubagentReply` contains the Subagent ID and complete reply text. Result,
  question and request are not separate public message kinds; the Agent in
  charge interprets the text and uses `subagent_send` when another child Turn
  is needed.
- The main Turn queue stores typed inputs rather than bare strings. Person
  input and `SubagentReply` share ordering but retain their provenance.
- A person's input is painted as their message. A `SubagentReply` is delivered
  to the Agent in charge with an envelope that includes the child ID, but is
  not painted or persisted as text authored by the person.
- The Agent in charge remains the only writer of the main History. A Reply
  starts a Turn immediately when the main Agent is idle; otherwise it joins the
  same FIFO queue as accepted person inputs. Two Turns never execute
  concurrently against the same Agent and History.
- The normal tool result is the only immediate response path for
  `subagent`, `subagent_send` and `subagent_status`. The later
  `ConversationPort` delivery is not a second return from a tool; it is a new
  typed conversation input produced by the same `Subagents` resource.
- The first version has no separate progress event feed. Starting a tool uses
  existing tool activity; while work is active, the model can call
  `subagent_status`; completion arrives as a `SubagentReply`. Child tool calls,
  files accessed, token fragments and reasoning are not projected into the
  main TUI.
- Subagent IDs and Histories live only for the current TUI process and are
  scoped to the parent Session. They are not stored in shared `Storage` and do
  not become Sessions.
- When a Command replaces the current History, the Conversation Runtime closes
  the old Session's port. This cancels child executions, clears child Turn
  queues and removes their IDs without requiring Clear or Resume commands to
  know about Subagents.
- The Conversation Runtime also closes the port when the TUI stops. A Reply
  arriving through a closed or obsolete port is rejected and cannot enter a
  different Session.
- Worker failure is terminal for that Subagent. The module records `failed`,
  clears queued child messages, delivers a readable failure Reply to the Agent
  in charge and rejects later `subagent_send` calls for that ID. There is no
  automatic retry; retrying means creating a new Subagent.
- Process output must not be connected directly to the parent terminal. The
  process execution Adapter must use contexts that keep worker stdout and
  stderr away from the Conversation TUI.
- Cancellation is wired through the process execution and cooperative waits.
  A transport-level Channel closure or cancellation exception is translated
  into a readable module result rather than leaking its implementation class
  into the conversation.
- The Host process discovers and supplies its Composer autoloader to workers,
  so the library works from an installed dependency and does not assume the
  package's own repository layout.

## Testing Decisions

- Tests assert externally observable behaviour rather than class layout,
  private collections, exact generated IDs, worker identity or repaint counts.
- The primary seam is the configured conversation: a fake Host Agent mounts the
  real `SubagentToolkit`, the Conversation TUI runs with a fake terminal and
  fake providers, and assertions observe tool results, queueing, displayed
  attribution and subsequent Agent Turns. This is the highest seam that proves
  the feature works as experienced by a person and by the Agent in charge.
- The primary integration tests cover immediate return of a new ID, continued
  use of the main conversation while child work is active, automatic delivery
  of a completed Reply, and continuation through `subagent_send` with the same
  child History.
- Conversation integration tests cover a Reply arriving while the main Agent
  is already answering and verify that it waits behind earlier accepted input
  without creating concurrent Turns on the main History.
- Conversation integration tests distinguish person input from Subagent Reply
  and verify that child content is not rendered as text authored by the person.
- The pure `Subagents` module is tested through a fake Turn executor for ID
  lookup, lifecycle transitions, FIFO child messages, status reads, History
  replacement, terminal failure and clearing queued work. These tests require
  neither an event loop nor a provider.
- The Turn queue's existing in-memory testing style is extended to mixed typed
  inputs, preserving the current coverage of acceptance, working and finish
  transitions.
- The process execution Adapter has focused integration tests with an
  autoloadable fake Agent and fake provider. They prove process isolation,
  JSON-compatible History round trips, worker reuse, bounded concurrency and
  cancellation without network access or API keys.
- A process test includes child tool calls backed by closures and verifies that
  the returned History crosses the process seam without serializing those
  closures.
- Failure tests cover worker crash, provider exception, cancellation and a late
  Reply after its parent port has closed. Assertions target the public failed
  state and delivered readable error rather than Amp exception text.
- Session lifecycle tests cover Clear, Resume and terminal shutdown through the
  Conversation Runtime's existing reconciliation behaviour. Commands remain
  unaware of the Subagent toolkit.
- Existing tests for `AgentTurn`, `TurnQueue`, `ConversationView`, TUI
  composition and storage-backed Chat History are the prior art for fake
  providers, fake terminals, streamed tool events, queue transitions and
  History round trips.
- The complete suite, static analysis and formatting checks must pass. No test
  may require a real provider credential or depend on timing alone to prove
  concurrency.

## Out of Scope

- Streaming child text, reasoning, tool activity, file activity or progress
  into the main TUI.
- Steering or interrupting an inference already running inside a child Turn.
- A public `subagent_cancel`, `subagent_list` or `subagent_wait` tool.
- Manual Esc cancellation of the main or child Turn.
- Automatic retries, worker migration recovery or revival of a failed ID.
- Persisting Subagent IDs or Histories in `Storage`.
- Resuming Subagents after restarting the TUI or reopening a parent Session.
- Representing a Subagent as a `Session` or exposing its History in the Session
  Picker.
- Allowing Subagents to create nested Subagents.
- Multiple child Agent classes or per-call Agent selection within one toolkit.
- A general Agent factory, dependency container protocol or arbitrary scalar
  configuration descriptor for worker construction.
- A dedicated process kept alive for the lifetime of each Subagent.
- A custom bidirectional parent-child messaging protocol.
- Shared task lists, peer-to-peer mailboxes or Claude-style agent teams.
- Changing Neuron's sequential execution of multiple tool calls in one parent
  response.
- Durable activity projections or replaying transient Subagent status after a
  Session resume.

## Further Notes

- A prototype with `amphp/parallel` verified bidirectional parent-worker
  communication, shared Revolt operation with Symfony TUI, continued painting
  while work is suspended, multiple worker PIDs, cancellation behaviour and
  injection between `ToolCallChunk` emission and tool execution. The final
  design deliberately needs less than the prototype: one child Turn maps to
  one execution and follow-up messages are queued for later Turns.
- The prototype measured roughly 45 ms for a cold worker, negligible startup
  on a warm worker, and about 568 ms for three parallel tasks where one task
  took about 520 ms. These measurements establish feasibility, not performance
  assertions for the test suite.
- Comparative research found the same stable-child abstraction in Codex child
  threads, Claude Code agent IDs and transcripts, and OpenCode child Sessions.
  Those systems expose stable conversational identity without making a
  dedicated operating-system process part of their user-facing contract.
- The final design is closest to OpenCode's automatic delivery into a later
  parent Turn, while retaining the separately named start, send and status
  tools chosen for clarity in this project.
- Earlier research text proposing a `Delegation` aggregate, a progress protocol
  or one overloaded tool is superseded by this specification and by the
  canonical `Subagent` term in the project glossary.
