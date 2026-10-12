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

Tui::make($conversation)->run();
```

Customize the header through fluent setters:

```php
Tui::make($conversation)
    ->setTitle('Filesystem Agent')
    ->setDescription('Explore, read and edit files with Neuron AI')
    ->run();
```

The TUI loads the Conversation's display history at startup and sends subsequent
inputs through `Conversation::sendInput()`. The Host Application configures the
session, commands and user message processors before passing the Conversation
to the TUI. Each TUI instance can run once.

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
