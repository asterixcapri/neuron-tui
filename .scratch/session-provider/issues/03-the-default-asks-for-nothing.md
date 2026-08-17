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

**Status:** resolved

- [x] `InMemorySessionProvider` ships with the library and takes no constructor
      arguments
- [x] Neuron CLI defaults to it when no provider is passed, and its public
      interface neither grows nor shrinks
- [x] Constructing Neuron CLI with the Agent alone and driving `/clear` and
      `/sessions` through a virtual terminal works end to end: a new Session
      starts, the previous one stays listed, and resuming one brings its
      conversation back
- [x] That same test proves no directory and no file appear on disk
- [x] The in-memory test double is deleted, and the feature tests use the
      shipped provider
- [x] ADR 0001 is rewritten: replacing the Agent's History and never calling
      `flushAll()` are still the decisions, but the replacement now follows
      from a provider the Host passed, and the ADR names the default that takes
      nothing

## Comments

Verified in the worktree: `composer stan` reports no errors on both
configurations, and `composer test` is green at 99 tests / 318 assertions with
no `.neuron` directory left behind.

`InMemorySessionProvider` ships in `src/Session/`, takes no constructor
arguments and holds an `InMemoryChatHistory` per key for the life of the
process; it lists the Sessions that were written in, newest minted first, and
says so in its own words. Neuron CLI falls back to it when no provider is
passed — the constructor keeps the same five arguments it had.

`testSessionsWorkWithNoProviderAndWriteNothingToDisk` drives `/clear`, a turn,
`/clear` and `/sessions` through a virtual terminal against
`new NeuronCli($agent, terminal: $terminal)`, resumes the listed Session and
asserts the working directory is byte-for-byte the same set of entries
afterwards, with no `.neuron` directory.

The test double under `tests/Session/` is deleted and the feature tests use the
shipped provider. Three of them lost the `sessions()` accessor the double had:
they now say the same thing through `list()`, `open()` and the History on the
Agent.

Review raised two things that were fixed before the work was closed: the file
provider's docblock still called itself what a Host Application configuring
nothing gets, and the in-memory `open()` minted a Session for a key it had
never handed out — an affordance the deleted test double needed and the seam's
own contract forbids. `open()` now refuses a key it did not mint, and the two
tests that had lost an identity assertion regained one by writing in the
History the Agent was left holding and watching the provider list it.

Beyond the checklist, and because ticket 03 makes the old wording untrue: the
README no longer calls the default a directory of files, the glossary notes
that a default Session ends with the process, and the PHPStan public-module
policy admits the shipped provider a Host may name. The README's required
directory and the example application belong to ticket 04.
