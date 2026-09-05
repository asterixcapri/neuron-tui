# Interaction composition review

Status: agreed; grilling complete; implementation pending

This grill-with-docs records the agreed design refinement after extraction.
The user confirmed the final command-mounting contract. No code changes have
been made for this refinement yet.

## Agreed direction

- The Host Application must supply the Agent and may supply Commands, Sessions
  and Input history. Supplied instances are reused. Omitted Commands defaults
  to an empty collection; omitted Sessions and Input history use in-memory
  Storage. Defaults are built once and reused. There is no Interaction
  dependency container.
- Command mounting belongs to Commands; Tui::addCommand is removed.
  Commands::addCommand mutates the existing collection and returns $this for
  chaining. It does not return a new collection.
- The Host Application installs the initial or resumed History on the Agent;
  the TUI preserves its conversation when starting. The initial conversation
  must participate in Sessions and remain resumable after clear, including
  when Sessions defaults to in-memory Storage and the Agent already has messages.
- Help and Leave belong to Neuron Interaction and implement the same
  CommandInterface as Clear and Resume. Their effects use Command controls.
- During a Turn, the TUI explicitly permits Help and Leave. No concurrent
  marker interface, abstract base, restricted controls or wrapper is needed.
- Slash prefixes belong to Command identifiers again. Parsing retains them;
  Help and suggestions display the identifiers directly.
- InputHistory already includes optional recall methods with cursor and draft
  local to each instance. Only the submitted-input sequence is persisted.

## Design tree

- Explicit module composition
  - Settled (Q1): Agent required; Commands defaults to empty; Sessions and
    Input history default to in-memory Storage when omitted.
    Confirmed with examples: each optional module may be supplied independently;
    supplied objects are reused and omitted defaults are created once.
  - Settled (Q2): configure Commands before running the TUI; dynamic changes
    during execution are outside this contract. addCommand mutates the existing
    collection and returns $this for chaining. No copies, freeze or lock
    mechanisms are introduced. The immutable-return proposal was rejected.
  - Settled: Host owns installation of the Agent's initial History.
  - Settled (Q4): the first conversation must remain resumable after clear even
    with default Sessions; existing Agent messages must be retained. Integrating
    that History with Sessions must preserve this guarantee.
- Shared Commands and terminal policy
  - Settled: Help and Leave use the ordinary Command contract.
  - Settled: only their implementations are admitted during a TUI Turn.
  - Settled: stop closes the Adapter without introducing cancellation or
    waiting for the Agent's in-flight work. The TUI stops processing its queue.
- Slash identifiers
  - Settled: the slash is retained end to end.
  - Settled (Q3): reject identifiers without the slash at mounting; no automatic
    prefix insertion or removal.

## Documentation to reconcile

The original spec still describes neutral identifiers, terminal-only Help and
Leave, and marker-based concurrency. ADR-0002 describes concurrent markers and
restricted controls. ADR-0003 describes Tui::addCommand and
frozen TUI configuration; ADR-0005 assigns module construction and initial
History replacement to the runtime. Their affected decisions must be explicitly
superseded as part of implementing this agreed refinement. CONTEXT.md now reflects
the agreed terminology, while executable code still reflects the earlier design.

## Verified facts

- Commands is currently immutable and accepts Commands and kits through its
  constructor. Incremental addition would be new shared behavior.
- Command suggestions snapshot names and descriptions at construction; Help
  reads the collection at execution. Dynamic changes would require reconciling
  those two behaviors.
- Sessions has no active-History field. The Host may supply any Agent History;
  resume lists the supplied Sessions collection and clear starts a History in it.
- stop closes the terminal and pending Picker without cancelling or awaiting
  the Agent Future. The queued messages do not continue through terminal ticks.
- Identifier lookup is exact and currently has no slash validation. The parser
  currently strips the first slash; that stripping is to be removed.
