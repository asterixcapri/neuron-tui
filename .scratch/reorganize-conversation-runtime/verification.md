# Conversation runtime integration verification

Verified on 2026-09-05 with PHP 8.5.8. This record covers all three implementation
tickets before the final branch review.

## Package revisions

The TUI implementation is the commit introducing this record, built on ticket
02 revision `e7594aed07cd4d9aa14bfe7411eac176e481c34e`. Its exact revision is
available with `git log --diff-filter=A --format=%H -- .scratch/reorganize-conversation-runtime/verification.md`.
The integration branch is `feat/reorganize-conversation-runtime`.

Both TUI Composer manifests and lock files consume remote
`asterixcapri/neuron-interaction` at
`dev-feat/command-adapter-execution#d823bce842c16d69d9432d4b7ea98ca21d618021`.
The shared package's own suite was run at that same source revision.

| Installation | Neuron AI | Symfony TUI | PHPStan | PHPUnit |
| --- | --- | --- | --- | --- |
| TUI root, committed `composer.lock` | 3.16.8 | 8.1.6 | 2.2.12 | 13.3.2 |
| TUI examples, committed `examples/composer.lock` | 3.16.5 | 8.1.6 | — | — |
| Neuron Interaction at `d823bce` | 3.16.10 | — | 2.2.13 | 13.3.2 |

All three installations have their own Composer vendor directory. The examples
reuse the existing path repository for the TUI source; the shared dependency
comes from the pinned remote revision. No installed vendor sources were edited.

Lock file SHA-256 values:

- TUI root: `3cad9b129897fb2489186b1e094be92c55580888d998a3e159e907241c2a93d2`.
- TUI examples: `8d6ad32d6fa2ea51a5af52d37c20952a0e96f3fd3f26c32339db9a0405ab486e`.
- Shared validation checkout's local, untracked lock:
  `97890148e2b13729838667f8b9b07d9dd455e2021c20883ffd838c7cfad1db08`.

## Results

| Check | Result |
| --- | --- |
| TUI `composer test` | 213 tests, 925 assertions passed. |
| TUI `composer stan` | Maximum level, no errors in source or tests. |
| Neuron Interaction `composer test` | 118 tests, 355 assertions passed. |
| Neuron Interaction `composer stan` | Maximum level, no errors. |
| Shared `php examples/backend.php` | Selection response and later Session resumption succeeded; Help and Leave checks passed. |
| Root and standalone example `composer install --no-interaction --prefer-dist` | Both installed successfully from their committed locks. |
| Root and example `composer validate` | Valid; Composer reports its expected advisory about the explicit commit pin. |
| Shared `composer validate --strict` | Valid. |
| Standalone example PHP syntax and autoload | All three example PHP files passed; Commands, Adapter, Tui, DemoAgent and ModelCommand autoloaded. |
| Standalone ModelCommand through `VirtualTerminal` | `/model` opened the Picker; its deferred choice replaced the Agent and displayed the confirmation. |
| `git diff --check` | Passed. |

The public TUI suite retains coverage for original History and supplied module
identity, constructor/make equivalence, configuration freezing, startup failures,
TTY validation, accepted-before-provider occupancy, queue order, streaming and
response errors, immediate exit during a response, Command admission and
suggestions, History reconciliation, deferred selection, and Input history and
keyboard ownership. Ticket 02 added the three missing selection regressions;
ticket 03 reuses that suite without new internal-wiring tests. Existing
Submission, TurnQueue and AgentTurn suites remain intact. The shared protocol
suite covers Adapter output and exception boundaries independently.

## Reproduction

At the TUI revision above, run `composer install --no-interaction --prefer-dist`,
`composer test`, and `composer stan`. In `examples/`, install its committed lock
separately and run `php -l` on `demo.php`, `src/DemoAgent.php` and
`src/ModelCommand.php`.

The following smoke check runs from `examples/` using that installation and
requires no credentials or provider requests:

```bash
php <<'PHP'
<?php
require 'vendor/autoload.php';

$terminal = new Symfony\Component\Tui\Terminal\VirtualTerminal(rows: 30);
Revolt\EventLoop::queue(static fn () => $terminal->simulateInput("/model\r"));
Revolt\EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\r"));
Revolt\EventLoop::delay(0.16, static fn () => $terminal->simulateInput("\x03"));
NeuronTui\Tui::make(
    new NeuronAI\Agent\Agent(),
    $terminal,
    commands: new NeuronInteraction\Command\Commands(new NeuronTuiDemo\ModelCommand()),
)->run();
$display = Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes($terminal->getOutput());
if (!str_contains($display, 'Model changed to openai:gpt-5.6-sol.')) {
    throw new RuntimeException('The example ModelCommand did not complete selection.');
}
echo "Example selection passed.\n";
PHP
```

Check out Neuron Interaction revision
`d823bce842c16d69d9432d4b7ea98ca21d618021` in its own repository. Its lock file
is not tracked; the tested direct dependency versions can be selected with
`composer update --no-interaction --prefer-dist --with neuron-core/neuron-ai:3.16.10 --with phpstan/phpstan:2.2.13 --with phpunit/phpunit:13.3.2`.
Run `composer test`, `composer stan`, and `php examples/backend.php` there.
