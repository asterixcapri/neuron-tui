# 06: Bootstrap Neuron Interaction with Storage and Sessions

**What to build:** Create an independently testable `asterixcapri/neuron-interaction` Composer library through which a non-terminal Host Application can persist, list and resume Sessions using shared Storage.

**Blocked by:** 01: Decouple Session titles from TUI projection.

**Status:** completed

- [x] The package is an independent Git repository and Composer library with root namespace `NeuronInteraction`.
- [x] It exposes the Storage contract, stored document representation and in-memory and file-backed implementations.
- [x] It exposes Session values, the Sessions collection and storage-backed Neuron AI History.
- [x] Starting, listing and resuming Sessions works without installing or referencing Neuron TUI.
- [x] Session titles are presentation-neutral and derive from the agreed user-authored History content.
- [x] Storage and Session behavior is covered through the package's public APIs, including both shipped Storage implementations.
- [x] The package provides no reader, fallback or migration for Neuron TUI's legacy persistence documents.
- [x] Existing in-repository implementations remain temporarily available so the current TUI branch stays verifiable during expansion.

## Comments

Implemented in `asterixcapri/neuron-interaction` commit `834ca0762518b9e47bcf9610d28b491b5b543e4f`, integrated by merge `6f014ac318df8e48c67e04ccc5f24cd76a2edec5`. Package verification passed: 52 tests, 105 assertions, PHPStan and Composer validation.
