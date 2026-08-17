# 05 — `/clear` opens a new Session

**What to build:** A person can start a fresh conversation without leaving the
TUI and without losing the one they were having. Typing `/clear` puts a new,
empty Session on the Agent: the screen clears, the composer empties, and the
previous conversation stays exactly where it was stored.

This is the first slice that goes end to end, so it brings the Session store
with it. The store is the one module that knows how a Session is addressed;
Neuron CLI never writes, serializes or parses a stored conversation. A
file-based adapter is the default, so a Host Application that configures
nothing still gets the command. An in-memory adapter lives in the test suite.

It also brings the interpretation of input: what a person types is either a
Slash command or a message for the Agent, and there are exactly three commands.

From the prototype — the whole of what the store asks of Neuron AI:

```php
$agent->setChatHistory(new FileChatHistory($directory, $key));
```

**Blocked by:** 03 — A History projection that can run at any moment; 04 — The
History pane owns heights and scrolling

**Status:** resolved

- [x] A Session store seam opens a Session — by key, or a newly minted one when
      no key is given — and returns a Neuron AI chat history
- [x] Keys are minted by the store; nothing outside it knows how a Session is
      addressed
- [x] The default adapter is file-based, in a project-relative directory; a
      Host Application can pass a different directory or a different adapter
- [x] An in-memory adapter exists in the test suite and is not shipped
- [x] Input is interpreted as either a Slash command or a message; `/clear`,
      `/sessions` and `/exit` are recognized, anything else unknown is rejected
      locally as it is today
- [x] `/clear` installs a new Session, clears the painted History and empties
      the composer
- [x] The previous conversation remains stored afterwards; `flushAll()` is
      never called anywhere in the codebase
- [x] `/clear` is refused while the Agent is working, with the reason shown in
      the conversation; `/exit` still works during a turn
- [x] The public interface grows by one optional argument, placed last, and
      existing construction keeps working unchanged
- [x] The file adapter is covered against a temporary directory

## Comments

**Startup still opens the History the Agent arrived with — a decision for the
effort, not for this ticket.** ADR 0001 says the configured History "is
replaced once the TUI starts", and user story 26 says the same. Doing that
here would have rewritten most of `NeuronCliTest`: two tests construct an
Agent with a populated History and assert it is painted and answered from,
and every other test would have started writing files under
`.neuron/sessions/` from the default adapter. This ticket's criteria only ask
that `/clear` install a Session, so the replacement happens on a Session
change and not before. The cost is real and belongs to 06: a conversation
held before the first `/clear` lives in the Host Application's own History and
so cannot be listed by `/sessions`. Whoever takes 06 should decide whether the
TUI opens a Session at startup — and, if so, edit those two tests knowingly.

**The store seam has one operation, `open()`.** Listing is 06's criterion and
had no caller here. Since the interface is published to Host Applications, 06
adds a method to a published interface; that is intended, not an oversight.

**`/sessions` is recognized but has no picker yet.** It says so in the
conversation rather than doing nothing. 06 replaces that line.

**`examples/prototype-sessions.php` is deleted.** It was the only remaining
`flushAll()` call in the codebase, which this ticket's criteria forbid, and
the spec's Further Notes already asked for the prototype to go once its
findings were folded in — `FileSessionStore` is where they landed. It stays
reachable in the history at `c409a5a`.

**Two things deliberately left alone.** The status line still reads
`… · /exit exits`, because an existing terminal test asserts that string
exactly. A refused command stays in the composer, the way an unknown one
does, so it can be resubmitted once the turn finishes.

Verified with `composer stan` and `composer test` from the worktree.
`NeuronCliTest::testHostApplicationCanCustomizeConversationBranding` failed
twice while a parallel agent loaded the machine and then passed eight
consecutive runs; it is the known flake, unrelated to this change.
