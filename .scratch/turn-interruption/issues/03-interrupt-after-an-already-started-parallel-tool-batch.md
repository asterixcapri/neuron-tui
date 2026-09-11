# 03: Interrupt after an already-started parallel tool batch

**What to build:** Treat a parallel tool batch that has begun as the current
indivisible work. After Escape is accepted, let every tool already started in
the batch settle with its real outcome, then end the Turn before any later
inference or tool batch and continue automatically with waiting input.

**Blocked by:** 02/Finish the current tool and skip later tools.

**Status:** ready-for-agent

- [ ] Every member of a parallel batch that began before interruption is allowed to settle.
- [ ] Successful, failed, and otherwise completed batch members retain their actual individual outcomes.
- [ ] The TUI does not claim to have selectively cancelled any member of the already-started batch.
- [ ] The visible interruption status remains active until the batch has settled.
- [ ] No Agent inference, sequential tool, or later parallel batch starts for the interrupted Turn after the active batch settles.
- [ ] Any tool calls requested for later work receive correlated not-executed technical results where required to keep the History valid.
- [ ] The first waiting message starts automatically after the batch outcomes and skipped outcomes are reconciled.
- [ ] Multiple waiting messages retain FIFO order and queue advancement occurs exactly once.
- [ ] With no waiting message, the Conversation TUI returns to ready after the batch settles.
- [ ] End-to-end tests verify actual execution counts, individual batch outcomes, absence of later work, History validity, visible status, and automatic queue advancement.

