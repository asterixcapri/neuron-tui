# 08: Extract Commands and native Session Commands

**What to build:** Make the Neuron Interaction package provide the complete headless Command model and native Session Commands so a Host Application can dispatch the same operations from a terminal or web Adapter.

**Blocked by:** 05: Make Command selection non-blocking; 06: Bootstrap Neuron Interaction with Storage and Sessions.

**Status:** ready-for-agent

- [ ] The package exposes Command interfaces, arguments, collection, execution outcome and Command Controls under the `NeuronInteraction` namespace.
- [ ] The package exposes `SelectionRequest` and `SelectionOption` as part of its Command module.
- [ ] Neutral dispatch, enumeration, duplicate handling, unknown identifiers and captured failures work without Neuron TUI.
- [ ] The package exposes Clear, Resume and the Session Command kit.
- [ ] Clear and Resume operate on the Sessions supplied through Command Controls rather than Adapter-specific collaborators.
- [ ] Resume follows the two-invocation Selection request protocol through public package tests.
- [ ] Help, Leave, concurrent Command types and concurrent controls do not enter the package.
- [ ] The package has no dependency on terminal rendering, Agent stream handling or an HTTP framework.

