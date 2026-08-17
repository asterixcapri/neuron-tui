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

## Sessions

A Session is one conversation with the Agent. `/clear` starts a fresh one
without leaving the terminal: the screen and the composer empty, and the
conversation that was on screen stays where it is stored.

Sessions live in a **Session store**. Without configuration they are files
under `.neuron/sessions`, relative to the working directory of the Host
Application. Another directory, or another place entirely, is one argument:

```php
use NeuronCli\Session\FileSessionStore;

(new NeuronCli(
    agent: $agent,
    sessionStore: new FileSessionStore('/var/lib/my-app/sessions'),
))->run();
```

An application that keeps conversations in its own storage implements
`NeuronCli\Session\SessionStore` instead. The store decides how a Session is
addressed and returns the Neuron AI chat history that Neuron CLI installs on
the Agent; saving, reloading and deserializing remain Neuron AI's work. Neuron
CLI never deletes a stored conversation.

Starting a Session replaces the History configured on the Agent by the Host
Application. An application that cares must pass a Session store reaching the
same place. See
[ADR 0001](docs/adr/0001-sessions-replace-the-agent-chat-history.md).

`NeuronCli\NeuronCli` is the public module, and `NeuronCli\Session\SessionStore`
with `NeuronCli\Session\FileSessionStore` the one dependency an application may
supply. Every other class under the `NeuronCli` namespace is annotated
`@internal`, carries no stability promise, and may be renamed, split, or
removed in any release. Static analysis enforces this on the examples, which
are the reference Host Application.

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
- `/clear` starts a new Session.
- `/exit` or Ctrl+C closes the Conversation TUI.

`/clear` is refused while the Agent is working, so an arriving answer cannot
land in the wrong Session; `/exit` works at any time. Unknown Slash commands
stay in the composer so they can be corrected and are never sent to the Agent.

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
