# Neuron TUI

A basic terminal interface for testing a [Neuron AI](https://github.com/neuron-core/neuron-ai)
Agent, built with [Symfony TUI](https://github.com/symfony/tui).

Requires PHP 8.4.1+ and an interactive terminal.

## Usage

Configure your Agent and its provider, then run:

```php
use NeuronTui\Tui;

Tui::make($agent)->run();
```

The conversation occupies the upper area, with the input bar fixed at the bottom
and the working indicator immediately above it.

Enter sends a message. Responses stream into the terminal, with tool activity
and errors displayed in the conversation. Ctrl+C exits. Input is unavailable
while the Agent responds. Each TUI instance runs once.

User messages use a highlighted row with `❯`; Agent responses use `●`. Tool
calls and results have separate indicators and colors, as do notifications,
system messages and errors. The same presentation applies to existing history
and live streaming.

`Tui` prepares the Agent, connects callbacks in `wire()` and runs the Symfony
terminal. `MainView` is the root `ContainerWidget` for the screen, handling
interface events and composing `HeaderView`, `HistoryView` and
`ComposerView`. `TurnRunner` executes and streams Agent turns.

`MainView` exposes `onInput()`, `onEscape()` and `onHistorySync()`, alongside
`appendResponse()`, `notify()`, `finishTurn()` and `stop()`.
`Tui::wire()` connects input to `TurnRunner::run()` and history synchronization
to the supplied Agent. A history callback returns an iterable of Neuron messages
for `HistoryView` to display. Escape invokes its callback during a turn; it has
no default cancellation behavior.

The supplied Agent keeps its own conversation history. An Agent without a thread
ID receives one when `Tui::make()` connects it to the interface.

This basic version consumes native Neuron stream chunks. Approval prompts and
external tool results are not handled. Blocking providers and tools can delay
processing of Ctrl+C until they yield control.

## Example

From the repository root:

```bash
composer --working-dir=examples install
php examples/basic.php
```

Create `examples/.env` with `OPENAI_API_KEY`, loaded through Symfony Dotenv.
The example selects its model directly in `basic.php`. The environment file is
ignored by Git.

## Development

```bash
composer install
composer cs:fix
composer stan
composer test
```

Tests use Neuron's fake provider and Symfony's virtual terminal, without credentials.
Previous domain documentation remains historical reference for the old implementation.

## License

MIT.
