# 01 — `SessionProvider` replaces `SessionStore`

**What to build:** Nothing changes for a person at the terminal. The seam a
Host Application implements is renamed for what it does and given three
operations that name themselves: create a Session, list the Sessions, open one
by key. The optional key that made `open()` mean two different things is gone —
minting a Session and opening one are separate operations. The description a
Session is listed with takes the name the glossary already gives it.

From the design conversation, the whole of the interface:

```php
interface SessionProvider
{
    public function create(): Session;

    /** @return list<Session> */
    public function list(): array;

    public function open(string $key): ChatHistoryInterface;
}
```

`Session` stays a description with no verbs — key, when it was last used, and
the title taken from the first message the person wrote — and knows nothing of
Neuron AI. The key is minted by the provider and by nothing else, so the only
keys `open()` can receive are the ones `list()` and `create()` handed out.

The file-based provider is renamed along with the seam and otherwise behaves
exactly as it does today, project-relative default directory included: where
Sessions live is decided in ticket 04, not here.

**Blocked by:** None — can start immediately.

**Status:** ready-for-agent

- [ ] `SessionProvider` has `create()`, `list()` and `open(string $key)`, and
      no operation takes an optional key
- [ ] `SessionSummary` is renamed `Session` and still carries key, last-used
      time and title, with no dependency on Neuron AI
- [ ] `FileSessionStore` is renamed `FileSessionProvider`, minting a key in
      `create()` and opening one in `open()`, with its directory behaviour
      unchanged
- [ ] The in-memory test double follows the rename and implements the three
      operations
- [ ] `/clear`, `/sessions`, resuming, cancelling and refusal during a turn
      behave exactly as before
- [ ] The terminal-level tests pass with no assertion edited — only imports and
      the named argument change. An assertion that has to change is a bug in
      the work
- [ ] The domain glossary replaces the **Session store** entry with **Session
      provider**, and its *Avoid* list warns off "store"
