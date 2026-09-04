# 01: Decouple Session titles from TUI projection

**What to build:** Make Session listing derive a shared, presentation-neutral title without using terminal History projection, while preserving the Session recognition behavior visible through Neuron TUI.

**Blocked by:** None (can start immediately).

**Status:** completed

- [x] A Session title comes from the first non-empty user-authored content in its History.
- [x] Session listing no longer depends on terminal History projection or terminal text utilities.
- [x] Empty Sessions remain absent from the list.
- [x] Sessions remain ordered by last use with the established deterministic tie-breaker.
- [x] Terminal escaping, truncation and rendering remain Adapter responsibilities.
- [x] Public Session and virtual-terminal behavior is covered without testing private helpers.
