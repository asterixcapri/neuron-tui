# 09: Switch Neuron TUI to Neuron Interaction

**What to build:** Make Neuron TUI consume the extracted package as its interaction model, preserving terminal behavior while removing the expanded local copies of shared modules.

**Blocked by:** 07: Extract persistent Input history; 08: Extract Commands and native Session Commands.

**Status:** ready-for-agent

- [ ] Neuron TUI composes package-provided Storage, Sessions, Input history and Commands.
- [ ] The TUI Adapter implements package-provided Command Controls and translates Selection requests into Picker interaction.
- [ ] Slash parsing and suggestions remain TUI responsibilities over neutral Command identifiers.
- [ ] Input history navigation remains TUI-owned over the package-provided persisted sequence.
- [ ] Help, Leave, concurrent Command types and marker-based concurrency policy remain in Neuron TUI.
- [ ] Ordinary Commands remain unavailable during an active Turn while concurrent Commands retain their established behavior.
- [ ] Shared local implementations are removed after all callers use the package, with no compatibility aliases.
- [ ] The existing public TUI and virtual-terminal suites pass against the package dependency.

