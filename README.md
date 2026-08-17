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
a production executable or Symfony Console command.

## Demo

`examples/` is a standalone Composer project acting as a Host Application. It
connects the Conversation TUI to OpenAI's Responses API and consumes this
library through a local path repository. Install its dependencies, create the
local environment file, add your credentials and model, then start it:

```bash
cd examples
composer install
cp .env.example .env
# Edit .env
composer demo
```

Packagist only publishes metadata: the archives themselves are served by
GitHub. Anonymous downloads are throttled, so a fresh install may report HTTP
429. Store a personal access token once to raise the limit:

```bash
composer config --global github-oauth.github.com <token>
```

The demo reads `examples/.env` through Symfony Dotenv. Existing process environment
variables take precedence over values from `examples/.env`. Use `/exit` or
Ctrl+C to close it.

## Controls

- Enter sends a message.
- Shift+Enter inserts a line break.
- Escape clears the unsent draft.
- PageUp and PageDown browse the History.
- `/exit` or Ctrl+C closes the Conversation TUI.

Only `/exit` is supported in this version. Unknown Slash commands stay in the
composer so they can be corrected and are never sent to the Agent.

## Development

A fresh checkout needs the Composer dependencies and the agent skills, which
are restored from `skills-lock.json`:

```bash
composer install
npx skills experimental_install
```

Then:

```bash
composer test
composer stan
```

The automated suite uses Neuron AI's fake provider and Symfony TUI's virtual
terminal. It requires no credentials and makes no network requests.

## License

Neuron CLI is released under the MIT License.
