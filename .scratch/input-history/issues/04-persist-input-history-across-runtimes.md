# 04: Persist Input history across Sessions and Runtime recreation

**What to build:** Preserve Input history independently of conversation lifecycle so earlier composer inputs remain available after Session changes and, with file-backed Storage, after closing and recreating the Conversation TUI.

**Blocked by:** 02/Navigate and edit Input history.

**Status:** completed

- [x] Clearing or changing a Session leaves the same Input history available to the Conversation TUI.
- [x] Conversation TUI instances sharing one InMemoryStorage instance share its Input history for that instance's lifetime.
- [x] FileStorage restores Input history after Conversation Runtime recreation with the same storage root.
- [x] The default in-memory composition creates no files or directories on disk while retaining entries for its live Conversation TUI.
- [x] Focused tests, static analysis and the complete test suite pass.
