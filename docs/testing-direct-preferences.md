# Testing direct preferences across the local repositories

This revision changes both Neuron Interaction and Neuron TUI. Test against the
sibling `../neuron-interaction` source checkout; installed dependency edits are
not the deliverable. Keep the existing Composer release constraints and lock
files unchanged. No dependency release is needed for local verification.

After installing dependencies in both repositories and in `examples/`, replace
these two installed directories with symlinks to the sibling Interaction
checkout (move the original directories aside first so they can be restored):

- `vendor/asterixcapri/neuron-interaction`
- `examples/vendor/asterixcapri/neuron-interaction`

The example's existing path repository already links Neuron TUI to this checkout.
Composer's PSR-4 autoloading then reads the changed Interaction sources through
those links. Reinstalling dependencies restores published packages; recreate
these links when testing this cross-repository revision again.

From the Neuron TUI root:

```bash
vendor/bin/phpunit tests/Tui/SessionCompositionTest.php
vendor/bin/phpunit -c examples/phpunit.xml.dist
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/phpstan analyse --no-progress --memory-limit=512M --autoload-file=examples/vendor/autoload.php examples/src examples/demo.php examples/tests
vendor/bin/phpunit
```

From the Neuron Interaction root:

```bash
vendor/bin/phpunit tests/Configuration/ConfigurationStoreTest.php
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
vendor/bin/phpunit
```

The demo tests construct providers with a dummy credential and never send a
request. They exercise real TUI Command controls through VirtualTerminal,
including selection completion, cancellation and visible failure handling.

Before this revision, the local Interaction dependency combination already
produced two SessionTest errors (`Cannot instantiate abstract class
NeuronAI\Tools\Tool`) and eleven PHPStan errors in that same test file involving
ToolCallMessage and ToolResultMessage APIs. Record these separately from
preference regressions; this work does not change Session or Neuron's contract.

## Verification of this revision

- Neuron TUI: full suite passed (218 tests, 971 assertions); PHPStan passed.
- Demo: all 5 tests passed (24 assertions); PHPStan passed for startup, source
  and tests using the demo autoloader.
- Neuron Interaction: full suite ran 159 tests with 576 assertions and only the
  two pre-existing Session errors above. Full PHPStan retained the same eleven
  pre-existing errors. Analysis of `src`, `tests/Configuration` and `examples`
  passed.
- Standards review found no issues. Spec review identified a Store lifetime
  issue in the backend selection example; both requests now share its Store,
  and follow-up review found no remaining issues. The example also ran successfully.
