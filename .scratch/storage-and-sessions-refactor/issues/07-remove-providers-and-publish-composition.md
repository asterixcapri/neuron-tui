# 07: Remove providers and publish the storage composition

**What to build:** Complete the public migration so applications configure storage directly, never construct a Session provider or install a Session History on the Agent, and all obsolete provider surfaces disappear.

**Blocked by:** 06: Connect Session Commands to runtime Sessions.

**Status:** ready-for-agent

- [ ] `SessionProvider`, `FileSessionProvider` and `InMemorySessionProvider` are removed without compatibility aliases, and no project code or type documentation references them.
- [ ] Public examples and README guidance demonstrate file persistence with one `FileStorage`, `setStorage()` and an explicitly mounted dependency-free `SessionKit`.
- [ ] The documented no-configuration path explains that storage remains in memory and writes nothing outside the process.
- [ ] The Host Application is no longer instructed or required to construct a History factory, implement `ChatHistoryInterface` or install a Session History on its Agent.
- [ ] No project-defined interface remains without the `Interface` suffix.
- [ ] Static analysis and the complete test suite pass after the obsolete provider tests and fixtures have been migrated or removed.
