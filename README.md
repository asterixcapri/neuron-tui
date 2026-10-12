# Neuron TUI

A terminal interface for testing [Neuron AI](https://github.com/neuron-core/neuron-ai)
agents, built with [Symfony TUI](https://github.com/symfony/tui).

The conversation appears above a multiline composer fixed at the bottom, with an
animated working indicator immediately above the input. History grows into the
terminal's native scrollback. Scroll and select text with normal terminal
gestures; the TUI does not capture the mouse. History and the composer share one
Symfony widget tree and renderer.

You can keep writing while the agent responds. Messages submitted during a turn
are queued and run in order; an unsubmitted draft stays in the editor. The
composer shows up to five lines, including wrapped text.
The hint below it shows the available actions for command menus and selections,
and changes from sending to queuing input while the agent is working.

![Neuron TUI demo: conversation, Markdown rendering and a filesystem tool](docs/images/neuron-tui-demo.gif)

## Requirements

- PHP 8.4.1 or later.
- Neuron AI 4.x.
- An interactive terminal for both standard input and standard output.

## Installation

Install with Composer:

```bash
composer require asterixcapri/neuron-tui
```

## Usage

Create a Conversation with a configured Neuron agent and a session:

```php
<?php

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;

$agent = Agent::make()
    ->setAiProvider(new OpenAIResponses(
        key: 'your-openai-api-key',
        model: 'gpt-5.4-nano',
    ))
    ->addTool(FileSystemToolkit::make(__DIR__));

$session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
$conversation = new Conversation($agent, $session);

(new Tui($conversation))->run();
```

Customize the header through fluent setters:

```php
(new Tui($conversation))
    ->setTitle('Filesystem Agent')
    ->setDescription('Explore, read and edit files with Neuron AI')
    ->run();
```

Add a FIGlet banner above the title and description:

```php
(new Tui($conversation))
    ->setFiglet('Neuron', 'small')
    ->setTitle('Filesystem Agent')
    ->setDescription('Explore, read and edit files with Neuron AI')
    ->run();
```

The font defaults to `standard`. Symfony TUI also includes `big`, `small`,
`slant` and `mini`. Pass an empty string as the text to hide the banner.

The TUI loads the Conversation's display history at startup and sends subsequent
inputs through `Conversation::sendInput()`. The Host Application configures the
session, commands and user message processors before passing the Conversation
to the TUI. Each TUI instance can run once.

## Slash commands

Type `/` to show the commands registered on the Conversation, with their
descriptions. Keep typing to filter by name. Use ↑/↓ to select, Tab to complete
and add arguments, Enter to run the selected command, and Esc to close the menu
while keeping the draft. The menu shows up to five commands and scrolls as you
move the selection. Commands submitted during a turn join the input queue.

Register commands before starting the TUI:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;

$conversation->setCommands(new Commands(new HelpCommand(), new ExitCommand()));
(new Tui($conversation))->run();
```

Custom implementations of `NeuronInteraction\Command\CommandInterface` appear
in the same menu. The Host Application owns the command registry.

Commands can emit a `SelectionRequest` to present options in place of the
composer. The panel shows a framed search field and each option’s description
below its label. Type to filter labels and descriptions; use ↑/↓ to choose,
Enter to confirm or Esc to cancel. Confirmation
submits a new `CommandInput` with the request's command and the option's original
value; cancellation submits nothing. The draft is restored when the selector
closes. Queued messages wait for the selection to be resolved, and the selected
command runs before them. `/resume` uses this selector to reopen a stored session.

## Example

From the repository root, install the example dependencies:

```bash
composer --working-dir=examples install
```

Copy the environment template:

```bash
cp examples/.env.example examples/.env
```

Set `OPENAI_API_KEY` in `examples/.env`.

Then run:

```bash
php examples/basic.php
```

For the same example with `/help`, `/clear`, `/resume` and `/exit` commands, run:

```bash
php examples/commands.php
```

For an advanced example with local session storage, run:

```bash
php examples/advanced.php
```

Sessions are saved as JSON files in `examples/.storage/`, which is created
automatically and ignored by Git. Each launch starts a new session. Use `/resume`
to reopen a saved conversation, including after restarting the example, or
`/clear` to start a new session while keeping the previous one stored.

The advanced example also saves input history in the same storage, shared across
sessions and application restarts. Use ↑/↓ to recall single-line inputs, or
Ctrl+P/Ctrl+N for multiline inputs. Moving past the newest entry restores the
draft. Editing a recalled input starts a new draft. Arrow keys keep their normal
editing behavior in multiline inputs and their selection behavior in menus.

Press Esc during a response in the advanced example to request a stop. The text
received so far remains in the session. Esc first closes an open command menu or
cancels a selection; press it with those closed to stop the response.

Enable recall in your Host Application before creating the TUI:

```php
use NeuronInteraction\InputHistory\InputHistory;

$conversation->setInputHistory(new InputHistory($storage));
```

The composer recalls the text of stored inputs; attachments are not restored.
Conversation records inputs when they are sent for execution, including commands.

## Development

```bash
composer install
composer cs:fix
composer stan
composer test
composer cs
```

## License

MIT.
