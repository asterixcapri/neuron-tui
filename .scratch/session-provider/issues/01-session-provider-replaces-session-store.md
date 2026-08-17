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

**Status:** resolved

- [x] `SessionProvider` has `create()`, `list()` and `open(string $key)`, and
      no operation takes an optional key
- [x] `SessionSummary` is renamed `Session` and still carries key, last-used
      time and title, with no dependency on Neuron AI
- [x] `FileSessionStore` is renamed `FileSessionProvider`, minting a key in
      `create()` and opening one in `open()`, with its directory behaviour
      unchanged
- [x] The in-memory test double follows the rename and implements the three
      operations
- [x] `/clear`, `/sessions`, resuming, cancelling and refusal during a turn
      behave exactly as before
- [x] The terminal-level tests pass with no assertion edited — only imports and
      the named argument change. An assertion that has to change is a bug in
      the work
- [x] The domain glossary replaces the **Session store** entry with **Session
      provider**, and its *Avoid* list warns off "store"

## Comments

Implemented on `ticket/01-session-provider`, on top of `41453af`.

- `SessionProvider` has `create(): Session`, `list(): array` and
  `open(string $key): ChatHistoryInterface`; no operation takes an optional
  key.
- `SessionSummary` is `Session` — key, `lastUsedAt`, title, and no Neuron AI
  in sight. `create()` returns one whose title is empty, because a Session
  nobody has written to has nothing to be titled by.
- `FileSessionStore` is `FileSessionProvider`: `create()` mints the hex key,
  `open()` builds the `FileChatHistory` for it. Directory behaviour, prefix,
  extension, listing and ordering are untouched, project-relative default
  included — that default is ticket 04.
- The in-memory test double is `InMemorySessionProvider` and implements the
  three operations. It still starts a Session under a key it never minted,
  which is how a test says what was written before the TUI opened; ticket 03
  replaces it with the shipped provider.
- `/clear` now goes through `startSession()`, which mints and then opens.
  Resuming, cancelling and refusal during a turn are unchanged.
- The terminal-level tests changed in three lines only: the import, the
  double's name and the `sessionProvider:` argument. No assertion was edited.
- The glossary entry is **Session provider**, and its *Avoid* list now warns
  off "store". README, ADR 0001 and the PHPStan public-module policy follow
  the rename; the ADR rewrite and the README's default belong to ticket 03.

Verification: `composer stan` clean (both configurations), `composer test`
green — 98 tests, 308 assertions.
