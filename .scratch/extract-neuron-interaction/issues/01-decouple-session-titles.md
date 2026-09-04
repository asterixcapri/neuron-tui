# 01: Decouple Session titles from TUI projection

**What to build:** Make Session listing derive a shared, presentation-neutral title without using terminal History projection, while preserving the Session recognition behavior visible through Neuron TUI.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] A Session title comes from the first non-empty user-authored content in its History.
- [ ] Session listing no longer depends on terminal History projection or terminal text utilities.
- [ ] Empty Sessions remain absent from the list.
- [ ] Sessions remain ordered by last use with the established deterministic tie-breaker.
- [ ] Terminal escaping, truncation and rendering remain Adapter responsibilities.
- [ ] Public Session and virtual-terminal behavior is covered without testing private helpers.

