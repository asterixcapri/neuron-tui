# Neuron TUI

A terminal interface for testing [Neuron AI](https://github.com/neuron-core/neuron-ai)
agents, built with [Symfony TUI](https://github.com/symfony/tui).

The conversation appears above a multiline composer fixed at the bottom, with an
animated working indicator immediately above the input.

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

Create a Neuron agent and pass it to the TUI:

```php
<?php

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronTui\Tui;

$agent = Agent::make()
    ->setAiProvider(new OpenAIResponses(
        key: 'your-openai-api-key',
        model: 'gpt-5.4-nano',
    ))
    ->addTool(FileSystemToolkit::make(__DIR__));

Tui::make($agent)->run();
```

Customize the header through fluent setters:

```php
Tui::make($agent)
    ->setTitle('Filesystem Agent')
    ->setDescription('Explore, read and edit files with Neuron AI')
    ->run();
```

The TUI loads the agent's existing conversation history at startup and keeps using
that agent for subsequent turns. If the agent has no thread ID, `Tui::make()`
assigns one. Each TUI instance can run once.

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
