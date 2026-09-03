# 05: Compose runtime-owned Sessions

**What to build:** Let the Host Application configure one storage on the TUI while the Conversation Runtime owns one live `Sessions` instance and installs its initial History on the Agent.

**Blocked by:** 01: Adopt Command language; 04: Manage the Session lifecycle.

**Status:** ready-for-agent

- [ ] `setStorage()` is fluent, follows the existing TUI setters and rejects mutation after startup under the same freeze-on-run rule.
- [ ] At startup the Conversation Runtime constructs exactly one `Sessions` instance from the configured storage, starts its initial Session and installs that History on the Agent.
- [ ] Without `setStorage()`, startup uses in-memory storage only and creates no directories or files.
- [ ] Normal Commands receive access to the runtime's exact `Sessions` instance through `Controls::sessions()`.
- [ ] `ConcurrentControls` does not expose Sessions, so a Concurrent Command cannot replace the conversation while a Turn is running.
- [ ] Existing fluent construction, mounted-Command order, History rendering, queuing, Picker behaviour and Agent handoff semantics remain covered.
