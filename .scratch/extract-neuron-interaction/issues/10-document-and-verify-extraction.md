# 10: Document and verify the extracted package

**What to build:** Give maintainers and Host Application developers a verified description of how to compose Neuron Interaction from TUI and backend environments, with the final package boundaries enforced by both test suites.

**Blocked by:** 09: Switch Neuron TUI to Neuron Interaction.

**Status:** completed

- [x] Neuron Interaction documentation explains direct composition of Commands, Sessions, Input history and Storage without a mandatory facade.
- [x] Documentation demonstrates how a backend Adapter implements Command Controls and carries a Selection request across separate requests without prescribing a framework.
- [x] Documentation explains that `promptAgent` hands work to the Host Application's normal Agent flow and that Agent response streaming is outside the package.
- [x] Neuron TUI documentation uses neutral Command identifiers at the package boundary and slash syntax only in terminal examples.
- [x] Documentation identifies terminal-only Commands and concurrency policy as Neuron TUI concerns.
- [x] Both package suites pass static analysis and tests independently.
- [x] The final dependency graph contains no reverse dependency from Neuron Interaction to Neuron TUI.
- [x] Authentication, subagent orchestration, worker topology and legacy persistence compatibility remain absent from the delivered scope.

## Verification

- Neuron Interaction commit `d67dd58`: 102 tests, 229 assertions; PHPStan at
  maximum level passes. The executable `examples/backend.php` completes a
  serialized selection round trip using fresh controls for the second request.
  Its tests also verify that generated prompts are handed to the Host
  Application without adding Input history entries.
- Neuron TUI: 210 tests, 868 assertions; PHPStan at maximum level passes.
  Root and demo Composer manifests validate; both lock files install Neuron
  Interaction from remote commit `b7b0c75610e4a882c4fbedd152ce1d47d0f60aaa`.
- Package manifest, source, tests and examples contain no Neuron TUI dependency
  or imports. The TUI owns its runtime, rendering, navigation and concurrency.
- Current API names are `CommandControlsInterface` and `SessionCommandKit`.
  Installation documentation identifies development VCS branches and explicit
  root-level repository/stability requirements; no release is claimed.

## Final review resolution

- Standards review reported no findings. The Spec review identified numeric
  string keys failing when listed through InMemoryStorage because PHP coerces
  array keys to integers. Package commit `5e691e8` restores the string at the
  document boundary and adds public regressions for both Storage adapters.
- The fixed package passes 104 tests and 237 assertions, with PHPStan at maximum
  level. Root and example locks now install the reviewed package from remote
  commit `5c4edb7ef6e7a8d0fff50ab32878d154780309ec`.
- Root and example Composer manifests validate strictly; the example installs
  from its lock successfully. TUI passes 210 tests and 868 assertions, plus
  PHPStan at maximum level, against the fixed dependency.
