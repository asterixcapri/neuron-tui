# Choose, resume, and start Sessions from the Conversation TUI

Status: ready-for-agent

## Problem Statement

A person who closes the Conversation TUI loses their way back into the
conversation. Neuron AI already keeps the messages — a persistent History
saves, reloads, and deserializes everything, tool calls included — but Neuron
CLI opens whatever History the Agent happens to hold and offers no way to
start a fresh conversation, to see which conversations exist, or to return to
one of them. `/exit` is the only Slash command.

Underneath, the code that would have to carry this cannot. The rules that
decide what appears on screen — which messages are hidden, how a tool call is
paired with its result, how a preview is sanitized — live inside the two
modules that also own the widgets, so they can only be exercised by booting
the whole Conversation TUI and reading back ANSI. Painting the History runs
once, at construction. Nothing can re-run it, which is exactly what returning
to a Session requires.

## Solution

Neuron CLI gains three Slash commands: `/clear` starts a new empty Session,
`/sessions` lists the Sessions of this Agent and lets a person choose one to
resume, and `/exit` closes the TUI as before.

Sessions are reached through a **Session store** — the one module that knows
which Sessions exist and how to reach one by its key. Neuron CLI ships a
file-based adapter as the default, built on Neuron AI's own `FileChatHistory`,
so a Host Application that configures nothing still gets all three commands. A
Host Application that keeps conversations in SQL or Eloquent supplies its own
adapter instead.

Persistence itself is not reimplemented. Neuron AI already saves, reloads, and
deserializes a conversation, and reopening one is constructing its History
again with the same key. The only thing Neuron CLI adds is the part Neuron AI
does not offer: finding out which keys exist.

The same spec deepens the modules the feature has to pass through. The rules
that decide what is displayed move out of the widget-owning modules and into
modules that can be tested without a terminal, and painting the History becomes
something that can happen at any moment rather than only at startup.

## User Stories

1. As a terminal user, I want to start a new empty Session without leaving the
   TUI, so that I can change subject without losing what I discussed before.
2. As a terminal user, I want my previous Session preserved when I start a new
   one, so that starting fresh is never destructive.
3. As a terminal user, I want to see the Sessions of this Agent, so that I can
   find the conversation I had earlier.
4. As a terminal user, I want the Sessions ordered with the most recent first,
   so that the one I am most likely to want is at hand.
5. As a terminal user, I want each Session labelled with when it was last used,
   so that I can tell recent work from old work.
6. As a terminal user, I want each Session labelled with the opening of the
   conversation, so that I can recognize it by subject rather than by key.
7. As a terminal user, I want to choose a Session with the arrow keys, so that
   selection needs no typing.
8. As a terminal user, I want to narrow a long list by typing, so that finding
   a Session stays quick when there are many.
9. As a terminal user, I want to abandon the choice with Escape, so that
   opening the list by accident costs me nothing.
10. As a terminal user, I want the chosen Session's History painted on screen,
    so that I can read what was said before continuing.
11. As a terminal user, I want to keep writing in a resumed Session, so that
    the conversation continues where it stopped.
12. As a terminal user, I want the Agent to answer with the resumed Session's
    context, so that resuming is real and not merely visual.
13. As a terminal user, I want Sessions that were never used to stay out of the
    list, so that the list holds conversations rather than false starts.
14. As a terminal user, I want the composer emptied when the Session changes,
    so that a draft meant for one conversation does not leak into another.
15. As a terminal user, I want to know which Slash commands exist, so that I do
    not have to guess.
16. As a terminal user, I want an unknown Slash command rejected locally, so
    that the reserved command namespace stays predictable.
17. As a terminal user, I want `/clear` and `/sessions` refused while the Agent
    is working, so that an arriving response cannot land in the wrong Session.
18. As a terminal user, I want the refusal explained on screen, so that I know
    to try again once the turn finishes.
19. As a terminal user, I want `/exit` to work even while the Agent is working,
    so that I am never trapped in the TUI.
20. As a terminal user, I want a resumed Session to hide the same things a
    fresh one hides — system messages, reasoning, raw payloads — so that
    returning to a conversation is never less safe than starting one.
21. As a terminal user, I want tool activity in a resumed Session shown the way
    live tool activity is shown, so that the History reads consistently.
22. As a terminal user, I want the view positioned at the newest message after
    resuming, so that I can continue immediately.
23. As a Host Application developer, I want the three commands to work without
    configuring anything, so that the library is useful out of the box.
24. As a Host Application developer, I want to choose where Sessions are kept,
    so that they can follow my project's conventions.
25. As a Host Application developer, I want to keep Sessions in the storage I
    already use, so that conversations do not end up split between a database
    and a directory of files.
26. As a Host Application developer, I want to know that the History I
    configured on the Agent is replaced once the TUI starts, so that the
    behaviour is a documented decision rather than a surprise.
27. As a Host Application developer, I want Neuron CLI never to delete a stored
    conversation, so that using the TUI cannot cost me data.
28. As a Host Application developer, I want the public interface to grow by at
    most one optional argument, so that existing integrations keep working
    unchanged.
29. As a library maintainer, I want the rules about what is displayed testable
    without a terminal, so that the most sensitive behaviour is not verified by
    scraping ANSI.
30. As a library maintainer, I want the redaction rules held in one module, so
    that a fix applies everywhere it should.
31. As a library maintainer, I want sanitizing and truncation held in one
    module, so that the four copies of that logic become one.
32. As a library maintainer, I want the pairing of a tool call with its result
    verifiable on plain data, so that out-of-order and missing results can be
    covered cheaply.
33. As a library maintainer, I want the queueing rules verifiable without an
    event loop, so that those tests stop depending on timing.
34. As a library maintainer, I want the working animation verifiable without
    sleeping, so that its throttling can be tested at all.
35. As a library maintainer, I want the height and scroll bookkeeping owned by
    one module, so that a caller cannot forget to report a change.
36. As a library maintainer, I want each module's directory to say which
    vocabulary it may use, so that the terminal and the Agent stop leaking into
    each other.
37. As a library maintainer, I want the existing terminal-level tests to keep
    passing untouched through the reorganisation, so that the deepening is
    demonstrably behaviour-preserving.

## Implementation Decisions

### Sessions

- A **Session store** is the only new public seam. Its interface has two
  operations: list the Sessions, and open one — by key, or a newly minted one
  when no key is given. It returns a Neuron AI chat history, which Neuron CLI
  installs on the Agent.
- Switching Session is `Agent::setChatHistory()` with what the store returned.
  Neuron CLI does not write, serialize, or parse stored conversations.
- `flushAll()` is never called. On a persistent History it deletes the stored
  conversation instead of archiving it, so "new empty Session" is always a new
  key. This is recorded in ADR 0001.
- The default adapter is file-based, over Neuron AI's `FileChatHistory`, in a
  project-relative directory. The Host Application may pass a different
  directory, or a different adapter entirely.
- Keys are minted by the store, never by the caller. Nothing outside the store
  knows how a Session is addressed.
- A Session summary carries its key, when it was last used, and a title taken
  from the first message the person wrote in it. The file adapter derives all
  three without parsing stored JSON by hand — it reopens the conversation
  through Neuron AI and reads the first user message.
- A Session that never received a message leaves nothing stored, so it cannot
  appear in the list. No special handling is needed for empty Sessions.
- Adapters for SQL and Eloquent are possible through the same interface and are
  not written now.
- The public interface of Neuron CLI grows by one optional Session store
  argument, placed last so that existing positional construction keeps working.

  From the prototype, the whole of what the store does with Neuron AI:

  ```php
  // start a new Session          open an existing one
  new FileChatHistory($dir, $newKey);   new FileChatHistory($dir, $key);
  // resume it
  $agent->setChatHistory($history);     // messages are already there
  ```

### Slash commands

- Three commands: `/clear`, `/sessions`, `/exit`. They are recognized by an
  interpretation module that turns raw input into either a command or a message
  for the Agent. No command registry and no extension point for Host
  Applications: two adapters would justify a seam, a fixed set of three does
  not.
- `/clear` opens a new Session. `/sessions` opens the Session picker. `/exit`
  stops the TUI.
- `/clear` and `/sessions` are refused while a turn is in flight, with an error
  in the conversation. `/exit` remains valid at all times. Queueing them is
  rejected: a command that silently takes effect half a minute later is worse
  than one that declines.
- Unknown Slash commands keep the existing behaviour.

### Session picker

- The Session picker is a state of the Conversation TUI, not a separate screen.
  While it is open the composer does not take text and the arrow keys move
  through the list.
- It is built on Symfony TUI's select-list widget, which already provides arrow
  navigation, type-to-filter, a bounded visible height, selection, and cancel.
- Escape leaves the picker with the current Session unchanged.

### Module layout

- Modules are grouped by vocabulary: History (projection of the Agent's
  messages), Conversation (turns, queueing, input interpretation), Session
  (the store and its adapters), and Tui (widgets, heights, scrolling). The
  `Internal` namespace segment is dropped; `@internal` annotations carry the
  stability promise instead.
- One-way dependency rule: History does not know Symfony TUI, Tui does not know
  Neuron AI, Conversation does not know widgets. Today both widget-owning
  modules import from Neuron AI and Symfony TUI at once, which is what makes
  them impossible to test apart.
- The History projection turns the Agent's messages into a single ordered
  stream of entries, text and tool activity interleaved, already correlated.
  Pairing a tool call with its result is internal to it, not something callers
  orchestrate.
- The projection is re-runnable at any moment. Resuming a Session and starting
  a new one both repaint from it; today it runs once, in the constructor.
- Sanitizing and truncation become one module with two operations: make text
  safe to display, and make a safe single-line preview of bounded width. It
  replaces four copies of the same call. The file-name placeholder stays a
  History rule, not a typography one.
- The history pane owns widgets, heights, gaps and scroll offset, and hands
  back an opaque handle for entries that change after being shown — streamed
  text and tool activity. The obligation to report a height change disappears
  from its interface. It also gains the reset the new commands need.
- The working indicator becomes one module owning frames, elapsed time,
  throttling and its widget. Time is passed in as a parameter rather than read
  from the clock or injected as an adapter, which makes both the elapsed
  counter and the throttle testable.
- Turn state and streaming are separate modules: the queue is pure — states,
  transitions, ordering — and the streaming module consumes the Agent's events.
  The line between them is the line between what can be tested without an event
  loop and what cannot.
- The public interface of Neuron CLI is otherwise unchanged: construct with an
  Agent and call `run()`.

### Sequencing

- The modules the Sessions pass through are deepened first: sanitizing, the
  History projection, the history pane, input interpretation. Each is an
  internal reshaping under an unchanged public interface and must leave the
  existing tests untouched and passing.
- Sessions are built on top of them.
- The working indicator and the turn state modules follow, and block nothing.

## Testing Decisions

- Good tests describe what a terminal user or a Host Application developer can
  observe. Widget composition, private methods, styles, and intermediate state
  are not test surfaces.
- **One feature seam, unchanged: the public Neuron CLI module.** All three
  commands, the Session picker, resuming, and refusal during a turn are
  verified there — driving a virtual terminal, asserting on ANSI-stripped
  output, exactly as the existing suite does.
- **One new public dependency seam: the Session store**, alongside the terminal
  and the Agent that Neuron CLI already accepts. Feature tests pass an
  in-memory adapter, which lives in the test suite rather than being shipped,
  until a Host Application asks for one.
- **Internal seams, tested directly**, because their rules are dense and a
  terminal adds nothing to the verification: the History projection (what is
  hidden, how entries are ordered, how a tool call finds its result), the
  sanitizing module (control bytes, invalid UTF-8, width), and the turn queue
  (what happens to a second message while the Agent is working). These modules
  are not public and can be reshaped without breaking anyone.
- The existing test suite is the prior art for the feature seam: driving a
  virtual terminal through the Revolt event loop, simulating key input,
  stripping ANSI, and using Neuron AI's fake provider for deterministic
  streamed text, tool calls, failures, and empty responses.
- The file-based Session store is covered against a temporary directory, with
  real `FileChatHistory` instances. It is the one place where the suite touches
  the filesystem.
- Coverage includes: starting a new Session and finding the previous one still
  listed; listing order and labels; choosing and resuming, including that the
  Agent answers with the resumed context; cancelling the picker; refusal of
  `/clear` and `/sessions` during a turn and `/exit` working during one; a
  resumed Session hiding system messages, reasoning and raw payloads; and the
  draft being cleared on a Session change.
- The suite makes no provider calls, needs no credentials, and performs no
  network requests.
- Through the reorganisation the existing terminal-level tests must keep
  passing without being edited. Any edit to them is evidence that a public
  behaviour changed.

## Out of Scope

- Shipping SQL or Eloquent Session store adapters.
- Renaming, deleting, exporting, merging, or searching within Sessions.
- Sessions shared across several Agents, or any notion of an Agent identity
  beyond the store the Host Application supplies.
- A Slash command registry, custom commands, aliases, or command help.
- Resuming a Session that is open in another process, and any locking or
  conflict handling between concurrent TUIs.
- Cancelling an in-flight turn, or applying a command after the turn ends.
- Migrating conversations that already exist in a Host Application's History
  into the Session store.
- Human-in-the-loop interruption, multimodal input, and every other exclusion
  already listed in the first spec, which continues to apply.
- Renaming the public class to avoid the collision with Neuron AI's own console
  class.

## Further Notes

- ADR 0001 records why the History configured by the Host Application is
  replaced, and why `flushAll()` is never used.
- A throwaway prototype exercised the whole Session lifecycle against real
  `FileChatHistory` instances — creating, listing, resuming, and the
  destructive behaviour of `flushAll()`. It answered the question the design
  turned on: everything except listing is already Neuron AI's work. Fold its
  findings in, then remove it or keep it on a prototype branch out of main.
- Neuron AI ships a class of its own named `NeuronCli`, its console for
  scaffolding and evaluation. The names collide for anyone importing both. Not
  addressed here, but worth deciding before a public release.
- Symfony TUI's select-list widget already covers the picker's interaction;
  nothing about arrow navigation or filtering needs to be built.
