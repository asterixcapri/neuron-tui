# 09: Switch Neuron TUI to Neuron Interaction

**What to build:** Make Neuron TUI consume the extracted package as its interaction model, preserving terminal behavior while removing the expanded local copies of shared modules.

**Blocked by:** 07: Extract persistent Input history; 08: Extract Commands and native Session Commands.

**Status:** done

- [x] Neuron TUI composes package-provided Storage, Sessions, Input history and Commands.
- [x] The TUI Adapter implements package-provided Command Controls and translates Selection requests into Picker interaction.
- [x] Slash parsing and suggestions remain TUI responsibilities over neutral Command identifiers.
- [x] Input history navigation remains TUI-owned over the package-provided persisted sequence.
- [x] Help, Leave, concurrent Command types and marker-based concurrency policy remain in Neuron TUI.
- [x] Ordinary Commands remain unavailable during an active Turn while concurrent Commands retain their established behavior.
- [x] Shared local implementations are removed after all callers use the package, with no compatibility aliases.
- [x] The existing public TUI and virtual-terminal suites pass against the package dependency.

## Comments

The root package and demo both declare the public GitHub VCS repository and
require `asterixcapri/neuron-interaction:dev-feat/extract-neuron-interaction`.
Both lockfiles resolve its remote commit `f432ed069d089b21c3aaf2af497530d3c610c01e`.
The demo keeps its existing local TUI path repository, with an explicit local
version so the checkout branch does not affect installation.

Transferred implementations and their package-owned tests are removed from
Neuron TUI. Shared kits are imported directly, with TUI-specific generic
annotations for concurrent members. Validation: 210 TUI tests / 868 assertions,
PHPStan at max, strict Composer validation, demo installation and autoload.
