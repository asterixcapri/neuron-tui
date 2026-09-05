# 08: Extract Commands and native Session Commands

**What to build:** Make the Neuron Interaction package provide the complete headless Command model and native Session Commands so a Host Application can dispatch the same operations from a terminal or web Adapter.

**Blocked by:** 05: Make Command selection non-blocking; 06: Bootstrap Neuron Interaction with Storage and Sessions.

**Status:** completed

- [x] The package exposes Command interfaces, arguments, collection, execution outcome and Command Controls under the `NeuronInteraction` namespace.
- [x] The package exposes `SelectionRequest` and `SelectionOption` as part of its Command module.
- [x] Neutral dispatch, enumeration, duplicate handling, unknown identifiers and captured failures work without Neuron TUI.
- [x] The package exposes Clear, Resume and the Session Command kit.
- [x] Clear and Resume operate on the Sessions supplied through Command Controls rather than Adapter-specific collaborators.
- [x] Resume follows the two-invocation Selection request protocol through public package tests.
- [x] Help, Leave, concurrent Command types and concurrent controls do not enter the package.
- [x] The package has no dependency on terminal rendering, Agent stream handling or an HTTP framework.

## Comments

Implemented in `asterixcapri/neuron-interaction` commit `1d661b9d2e22c1d5bad3b7a8c5dc9c74bef2a5e0`, integrated by merge `f432ed069d089b21c3aaf2af497530d3c610c01e`. Package verification passed: 100 tests, 224 assertions, PHPStan and Composer validation.
