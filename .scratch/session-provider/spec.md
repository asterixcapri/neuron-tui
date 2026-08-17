# The Session provider, and a default that takes nothing

Status: ready-for-agent

## Problem Statement

A Host Application that installs Neuron CLI and configures nothing gets a
directory it never asked for. Neuron CLI creates `.neuron/sessions` under the
working directory, writes conversations into it, and — if the Host had
configured a persistent History on the Agent — stops writing to the Host's own
storage without saying so. The Host declared where its conversations live; the
TUI silently decided otherwise. Today that surprise is documented rather than
removed: an ADR explains that the History is replaced and that a Host who cares
must pass a store reaching the same place.

Underneath, the seam that carries this says the wrong things. It is called a
store but stores nothing — Neuron AI saves, reloads and deserializes every
conversation. To list Sessions it has to restate what `FileChatHistory` already
knows, copying the file prefix and extension so the two do not drift. It hides
two different intentions behind one optional argument: opening a Session that
exists and minting one that does not are both `open()`, told apart by whether
the caller passed `null`. And the Session it describes is a summary with no
verbs, so the key that addresses it has to travel out of the store, through the
view and a terminal widget, before coming back to be used — packed into a
widget value beside the title with a null byte to keep them apart.

## Solution

The seam is renamed for what it does and given three operations that name
themselves: a **Session provider** creates a Session, lists the Sessions, and
opens one by key. Nothing else about the feature changes — the three Slash
commands, the picker, resuming, and the refusal during a turn all behave
exactly as they do today.

The default provider keeps its Sessions in memory and takes no arguments, which
is the same promise Neuron AI already makes to an Agent given no History at
all: a conversation that works completely and lasts as long as the process. A
Host Application that wants Sessions on disk passes a file provider and says
where — the directory is required, and Neuron CLI never picks one.

A provider is paired with a kind of Neuron AI chat history, because listing is
knowledge each kind already has: the file provider knows it is looking at
`FileChatHistory` files, and a Host on SQL or Eloquent writes a provider that
asks its own storage the same three questions. Passing a provider becomes the
one place a Host says where its conversations live, so the History on the Agent
is never replaced by a decision nobody made.

## User Stories

1. As a Host Application developer, I want Neuron CLI to write nothing to disk
   unless I ask it to, so that installing a terminal interface cannot leave
   files in my project.
2. As a Host Application developer, I want the three Slash commands to work
   without configuring anything, so that the library is still useful out of the
   box.
3. As a Host Application developer, I want the default Sessions to last as long
   as the process, so that the promise matches the one Neuron AI already makes
   for an Agent with no History.
4. As a Host Application developer, I want to say where Sessions are kept when
   I want them kept, so that the location is my decision and not a convention I
   have to discover.
5. As a Host Application developer, I want the file provider to refuse to guess
   a directory, so that a Session never lands somewhere I did not name.
6. As a Host Application developer, I want the provider I pass to determine the
   kind of History installed on the Agent, so that Sessions and storage cannot
   disagree.
7. As a Host Application developer, I want to keep Sessions in the storage I
   already use, so that conversations are not split between a database and a
   directory of files.
8. As a Host Application developer, I want the provider interface to ask only
   what my storage can already answer, so that implementing one is small work.
9. As a Host Application developer, I want the operation that starts a Session
   named differently from the one that opens an existing Session, so that I
   cannot implement one thinking of the other.
10. As a Host Application developer, I want Neuron CLI never to delete a stored
    conversation, so that using the TUI cannot cost me data.
11. As a Host Application developer, I want the public interface to keep taking
    the Agent alone, so that existing integrations keep working unchanged.
12. As a terminal user, I want `/clear` to start a fresh Session exactly as it
    does today, so that the rename costs me nothing.
13. As a terminal user, I want `/sessions` to list, filter, resume and cancel
    exactly as it does today, so that the rename costs me nothing.
14. As a terminal user, I want the Sessions listed most recently used first,
    labelled by when they were last used and how the conversation opened, so
    that I can recognize the one I want.
15. As a terminal user, I want `/clear` and `/sessions` still refused while the
    Agent is working, so that an arriving answer cannot land in the wrong
    Session.
16. As a terminal user, I want a Session with a title containing anything at
    all to be listed and resumable, so that punctuation in my first message
    cannot break the picker.
17. As a library maintainer, I want the seam named for what it does rather than
    for a pattern, so that its name stops promising storage it does not own.
18. As a library maintainer, I want the key never to leave the provider except
    inside a Session, so that no intermediate layer handles a string it cannot
    interpret.
19. As a library maintainer, I want the picker to carry Sessions rather than
    packed strings, so that the separator trick disappears.
20. As a library maintainer, I want the in-memory provider shipped rather than
    kept in the test suite, so that the default path is the one the tests
    exercise.
21. As a library maintainer, I want the terminal-level assertions to survive the
    rename untouched, so that the change is demonstrably behaviour-preserving.
22. As a library maintainer, I want the ADR to record the decision that
    replaced it, so that the documented surprise does not outlive the surprise.

## Implementation Decisions

### The Session provider

- `SessionStore` becomes `SessionProvider`, in the same module. It has three
  operations: create a Session, list the Sessions, and open a Session by key.
  Opening returns the Neuron AI chat history that Neuron CLI installs on the
  Agent.

  ```php
  interface SessionProvider
  {
      public function create(): Session;

      /** @return list<Session> */
      public function list(): array;

      public function open(string $key): ChatHistoryInterface;
  }
  ```

- `create()` and `open()` are separate because they are different intentions.
  The optional key that told them apart is gone: `open()` takes a key and
  always has one.
- The contract on `open()` is that its argument came from `list()` or from
  `create()`. Keys are minted by the provider and by nothing else, so no other
  origin is possible.
- `SessionSummary` is renamed `Session`, matching the domain glossary. It stays
  a description with no verbs — key, when it was last used, and the title taken
  from the first message the person wrote. It does not know Neuron AI.
- A provider is written against one kind of chat history, because listing is
  knowledge that kind already holds. Neuron CLI ships the two that need no
  infrastructure; SQL and Eloquent providers are possible through the same
  interface and are not written now.
- Neuron CLI never calls `flushAll()`, never writes, serializes or parses a
  stored conversation, and never deletes one. Unchanged, and still true.

### The default provider

- `InMemorySessionProvider` is the default and takes no constructor arguments.
  Its Sessions are `InMemoryChatHistory` instances held for the life of the
  process.
- It is promoted out of the test suite into the shipped library. The test
  double it replaces is deleted rather than kept alongside.
- Nothing in memory records when a conversation was written to, so the order
  Sessions were created in stands for how recently they were used: the newest
  first. This is a stated convention, not a measurement.
- The History the Host Application configured on the Agent is still replaced,
  because a provider builds every History it hands back. What changes is that
  the replacement now follows from an argument the Host passed. A Host that
  configured persistence and wants it under the TUI passes the matching
  provider.

### The file provider

- `FileSessionStore` becomes `FileSessionProvider`, and its directory is a
  required constructor argument. The `.neuron/sessions` default and the
  `getcwd()` call disappear.
- Everything else about it is unchanged: keys minted as random hex, files
  named by `FileChatHistory` from a prefix and extension passed on both sides,
  listing by reopening each conversation through Neuron AI and asking the file
  only when it was last written.
- It stays in the shipped library rather than moving to the examples, because
  it is the provider a Host that wants persistence and configures nothing else
  will reach for.

### What the change touches elsewhere

- Neuron CLI takes `?SessionProvider` as its last optional argument, in place
  of `?SessionStore`, and defaults to `InMemorySessionProvider`. The public
  interface neither grows nor shrinks.
- The Session picker carries `Session` objects instead of strings that pack a
  title and a key around a null-byte separator. The separator, and the risk of
  a title containing one, go away. The chosen Session is what comes back to
  Neuron CLI, which asks the provider to open it by its key.
- ADR 0001 is rewritten, not deleted: the decision it records — that the
  History is replaced, and that `flushAll()` is never called — is still the
  decision, but the consequence it warns about is now a consequence of an
  explicit argument. It should name the default that takes nothing.
- The README's Sessions section documents the required directory, the in-memory
  default and the three operations, and drops the `.neuron/sessions`
  convention.
- The domain glossary replaces the **Session store** entry with **Session
  provider** and keeps the *Avoid* list, which already warns off "repository",
  "storage" and "archive" — and now also warns off "store".
- The example application passes a file provider with an explicit directory,
  which is what a Host Application reading it should copy. The directory it
  uses is ignored by git.

## Testing Decisions

- Good tests describe what a terminal user or a Host Application developer can
  observe. Widget composition, private methods, styles and intermediate state
  are not test surfaces.
- **The feature seam is unchanged: the public Neuron CLI module.** All three
  commands, the picker, resuming and refusal during a turn stay verified there,
  driving a virtual terminal and asserting on ANSI-stripped output. The
  assertions in those tests do not change. Their setup does: the import and the
  named argument follow the rename. An assertion that has to change is evidence
  of a behaviour change and is a bug in the work, not in the test.
- **The public dependency seam is unchanged in kind and renamed in name:**
  `SessionProvider`, alongside the terminal and the Agent that Neuron CLI
  already accepts. Feature tests keep passing a provider explicitly.
- **New at the feature seam:** constructing Neuron CLI with no provider at all
  and driving `/clear` and `/sessions` through the virtual terminal, proving
  that the out-of-the-box path works and that no directory appears on disk
  while it does. This is the case the old default made untestable, because
  exercising it meant writing files.
- **The file provider is tested directly** against a temporary directory with
  real `FileChatHistory` instances, as it is today. Its existing coverage
  carries over renamed: a new Session starts empty, starting one leaves the
  previous stored, a stored Session reopens by its key, the directory is
  created when absent, nothing is listed before a Session is stored, Sessions
  are listed most recently used first, a Session is titled by the first thing
  the person wrote, and a Session that received no message is not listed. The
  test that asserted the project-relative default is replaced by one asserting
  that the directory is required.
- **The in-memory provider is covered through the feature seam** rather than
  directly. It has no rules of its own beyond the three operations, and the
  terminal tests exercise all three.
- A title containing the null byte that the picker used as a separator is worth
  one test at the feature seam, because the separator is what this change
  removes.
- The suite makes no provider calls, needs no credentials, performs no network
  requests, and touches the filesystem in exactly one file.

## Out of Scope

- Shipping SQL or Eloquent Session providers.
- Any change to what a terminal user sees or can do. This spec renames a seam,
  changes a default and moves a decision to the Host Application; the
  Conversation TUI behaves as before.
- Moving the listing capability upstream into Neuron AI, so that each chat
  history answers for itself which conversations exist. That is the shape this
  design is pointing at and it is a separate conversation with another project.
- Adopting the History the Agent arrived with as a Session the person can
  return to. It cannot carry a key or a time, and the provider that would list
  it cannot find its siblings; if it is ever wanted, it is its own spec.
- Renaming, deleting, exporting, merging or searching within Sessions.
- Everything already excluded by the Sessions spec, which continues to apply.

## Further Notes

- The reason the file provider has to restate `FileChatHistory`'s prefix and
  extension is that `ChatHistoryInterface` exposes nothing about identity — five
  methods, none of which is a key — and `FileChatHistory` keeps its directory
  and key protected. That is why a provider is bound to a kind of history
  rather than to the interface, and why the capability belongs upstream one day.
- The domain glossary defines a Session as outliving the TUI process. The
  in-memory default does not, and it is not trying to: it is what a person gets
  when nobody has said where Sessions should live. The glossary entry may want
  a sentence acknowledging that.
- Neuron AI ships a class of its own named `NeuronCli`. The names still collide
  for anyone importing both, and it is still worth deciding before a public
  release.
