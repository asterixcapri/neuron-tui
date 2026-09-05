# 07: Extract persistent Input history

**What to build:** Make the Neuron Interaction package persist one Input history sequence that a terminal Adapter and a non-terminal Adapter can share without sharing navigation state.

**Blocked by:** 02: Separate Input history persistence from navigation; 06: Bootstrap Neuron Interaction with Storage and Sessions.

**Status:** completed

- [x] Neuron Interaction exposes the persistence-facing Input history over its Storage contract.
- [x] One configured Storage holds one sequence independent from Sessions.
- [x] Separate Input history instances over the same Storage observe the same recorded sequence.
- [x] Messages and Command invocations are recorded as submitted, while blank entries, consecutive duplicates and generated Agent prompts follow the agreed rules.
- [x] No cursor, draft or older/newer navigation state enters the package.
- [x] The package behavior is tested through public APIs with in-memory and persistent Storage where relevant.
- [x] The temporary TUI-side implementation remains usable until the contract step removes duplication.

## Comments

Implemented in `asterixcapri/neuron-interaction` commit `f53a911c87837e186bef3892410725480d76e9fa`, integrated by merge `40674daa6131062a2d8e4ee7808bc78a63a8fe39`. Package verification passed: 58 tests, 125 assertions and PHPStan.
