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

**Status:** ready-for-agent

- [ ] A Session store seam opens a Session — by key, or a newly minted one when
      no key is given — and returns a Neuron AI chat history
- [ ] Keys are minted by the store; nothing outside it knows how a Session is
      addressed
- [ ] The default adapter is file-based, in a project-relative directory; a
      Host Application can pass a different directory or a different adapter
- [ ] An in-memory adapter exists in the test suite and is not shipped
- [ ] Input is interpreted as either a Slash command or a message; `/clear`,
      `/sessions` and `/exit` are recognized, anything else unknown is rejected
      locally as it is today
- [ ] `/clear` installs a new Session, clears the painted History and empties
      the composer
- [ ] The previous conversation remains stored afterwards; `flushAll()` is
      never called anywhere in the codebase
- [ ] `/clear` is refused while the Agent is working, with the reason shown in
      the conversation; `/exit` still works during a turn
- [ ] The public interface grows by one optional argument, placed last, and
      existing construction keeps working unchanged
- [ ] The file adapter is covered against a temporary directory
