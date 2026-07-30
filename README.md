# Neuron CLI

Neuron CLI is a reusable Conversation TUI for
[Neuron AI](https://github.com/neuron-core/neuron-ai). A Host Application
supplies a configured Agent; this library owns only the interactive terminal
experience.

## Installation

```bash
composer require asterixcapri/neuron-cli
```

Neuron CLI requires PHP 8.4.1 or newer and an interactive TTY.

## Usage

Configure the Agent in your application, then pass it to `NeuronCli`:

```php
use NeuronAI\Agent\Agent;
use NeuronCli\NeuronCli;

$agent = new Agent();
$agent->setAiProvider($provider);

(new NeuronCli($agent))->run();
```

The default header uses generic Neuron AI branding. A title and subtitle can
be supplied when the terminal should identify a particular Agent or product:

```php
(new NeuronCli(
    agent: $agent,
    title: 'Research Agent',
    subtitle: 'Ask about the knowledge base',
))->run();
```

The Host Application remains responsible for constructing the Agent,
providers, credentials, tools, History persistence, and the script or
framework command that launches the interaction. Neuron CLI does not provide
an executable or Symfony Console command.

## Controls

- Enter sends a message.
- Shift+Enter inserts a line break.
- Escape clears the unsent draft.
- PageUp and PageDown browse the History.
- `/exit` or Ctrl+C closes the Conversation TUI.

Only `/exit` is supported in this version. Unknown Slash commands stay in the
composer so they can be corrected and are never sent to the Agent.

## Development

```bash
composer test
composer stan
```

The automated suite uses Neuron AI's fake provider and Symfony TUI's virtual
terminal. It requires no credentials and makes no network requests.

## License

Neuron CLI is released under the MIT License.
