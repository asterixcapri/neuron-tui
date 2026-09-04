# 06: Bootstrap Neuron Interaction with Storage and Sessions

**What to build:** Create an independently testable `asterixcapri/neuron-interaction` Composer library through which a non-terminal Host Application can persist, list and resume Sessions using shared Storage.

**Blocked by:** 01: Decouple Session titles from TUI projection.

**Status:** ready-for-agent

- [ ] The package is an independent Git repository and Composer library with root namespace `NeuronInteraction`.
- [ ] It exposes the Storage contract, stored document representation and in-memory and file-backed implementations.
- [ ] It exposes Session values, the Sessions collection and storage-backed Neuron AI History.
- [ ] Starting, listing and resuming Sessions works without installing or referencing Neuron TUI.
- [ ] Session titles are presentation-neutral and derive from the agreed user-authored History content.
- [ ] Storage and Session behavior is covered through the package's public APIs, including both shipped Storage implementations.
- [ ] The package provides no reader, fallback or migration for Neuron TUI's legacy persistence documents.
- [ ] Existing in-repository implementations remain temporarily available so the current TUI branch stays verifiable during expansion.

