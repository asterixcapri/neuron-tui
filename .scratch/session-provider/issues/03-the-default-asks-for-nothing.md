# 03 — The default provider asks for nothing

**What to build:** A Host Application that constructs Neuron CLI with an Agent
and nothing else gets all three Slash commands working and not a single file
written. Sessions are kept in memory and last as long as the process, which is
the promise Neuron AI already makes to an Agent given no History at all. A Host
that wants conversations on disk says so by passing a provider.

The in-memory provider stops being a testing tool and becomes the shipped
default, so the path a Host gets for free is the path the suite exercises. The
test double it replaces is deleted rather than kept beside it.

Nothing in memory records when a conversation was written to, so the order
Sessions were created in stands for how recently they were used — newest first.
That is a stated convention, not a measurement, and it belongs in the module's
own words.

**Blocked by:** 01 — `SessionProvider` replaces `SessionStore`.

**Status:** ready-for-agent

- [ ] `InMemorySessionProvider` ships with the library and takes no constructor
      arguments
- [ ] Neuron CLI defaults to it when no provider is passed, and its public
      interface neither grows nor shrinks
- [ ] Constructing Neuron CLI with the Agent alone and driving `/clear` and
      `/sessions` through a virtual terminal works end to end: a new Session
      starts, the previous one stays listed, and resuming one brings its
      conversation back
- [ ] That same test proves no directory and no file appear on disk
- [ ] The in-memory test double is deleted, and the feature tests use the
      shipped provider
- [ ] ADR 0001 is rewritten: replacing the Agent's History and never calling
      `flushAll()` are still the decisions, but the replacement now follows
      from a provider the Host passed, and the ADR names the default that takes
      nothing
