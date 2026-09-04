# 07: Extract persistent Input history

**What to build:** Make the Neuron Interaction package persist one Input history sequence that a terminal Adapter and a non-terminal Adapter can share without sharing navigation state.

**Blocked by:** 02: Separate Input history persistence from navigation; 06: Bootstrap Neuron Interaction with Storage and Sessions.

**Status:** ready-for-agent

- [ ] Neuron Interaction exposes the persistence-facing Input history over its Storage contract.
- [ ] One configured Storage holds one sequence independent from Sessions.
- [ ] Separate Input history instances over the same Storage observe the same recorded sequence.
- [ ] Messages and Command invocations are recorded as submitted, while blank entries, consecutive duplicates and generated Agent prompts follow the agreed rules.
- [ ] No cursor, draft or older/newer navigation state enters the package.
- [ ] The package behavior is tested through public APIs with in-memory and persistent Storage where relevant.
- [ ] The temporary TUI-side implementation remains usable until the contract step removes duplication.

