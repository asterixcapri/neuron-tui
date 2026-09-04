# 02: Separate Input history persistence from navigation

**What to build:** Separate the globally persisted sequence of submitted inputs from the transient cursor and draft used to navigate it, without changing the user's terminal recall experience.

**Blocked by:** None (can start immediately).

**Status:** completed

- [x] The persistence-facing Input history owns only the ordered submitted-input sequence and its recording rules.
- [x] Blank inputs and consecutive duplicates retain their established behavior.
- [x] Messages and Command invocations are both recorded as originally submitted.
- [x] Navigation position, older/newer movement, draft restoration and leaving navigation are owned by the TUI Adapter.
- [x] Two navigation instances may use the same persisted sequence without sharing cursor state.
- [x] Existing terminal history navigation remains externally unchanged through virtual-terminal tests.
