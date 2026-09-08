# Neuron TUI

A ready-to-use terminal UI for working with or testing your
[Neuron AI](https://github.com/neuron-core/neuron-ai) agent. Manage conversation
sessions, add commands, and recall previous inputs—all from the terminal.

Built with [Symfony TUI](https://github.com/symfony/tui).

Sessions, commands, and input history are powered by
[Neuron Interaction](https://github.com/asterixcapri/neuron-interaction), so you
can use the same features in backend applications too.

Requires PHP 8.4.1+ and an interactive terminal.

## Installation

The `0.9.x` branch targets Neuron AI 4, currently available as `4.x-dev`.
Use `0.8.x` for Neuron AI 3.

Run this command in your application's directory:

```bash
composer require asterixcapri/neuron-tui:0.9.x-dev asterixcapri/neuron-interaction:0.9.x-dev "neuron-core/neuron-ai:^4.0@dev"
```

This development branch requires the matching Neuron Interaction `0.9.x`
changes. Until those changes are published, use adjacent local checkouts as
described under Development.

![Neuron TUI demo](docs/images/usage.gif)

## Usage

Implement the creation interface on your Agent and register its class. Tui creates
the initial Agent from the selected identifier and the supplied configuration store:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronInteraction\Agent\ConfiguredAgentInterface;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;

final class MyAgent extends Agent implements ConfiguredAgentInterface
{
    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        $model = $configurationStore->read('agent')?->get('model');
        if (!is_string($model) || $model === '') {
            throw new RuntimeException('A model is required.');
        }
        $agent = new static();
        $agent->setAiProvider(new OpenAIResponses(
            key: (string) getenv('OPENAI_API_KEY'),
            model: $model,
        ));

        return $agent;
    }
}

use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;

$configurationStore = new ConfigurationStore(new InMemoryStorage(), 'local');
$configurationStore->create('agent', ['model' => 'gpt-5.4-nano']);
$agentFactoryRegistry = new AgentFactoryRegistry();
$agentFactoryRegistry->register('assistant', MyAgent::class);

Tui::make(
    agentFactoryRegistry: $agentFactoryRegistry,
    initialAgentIdentifier: 'assistant',
    configurationStore: $configurationStore,
)->run();
```

The example uses `OPENAI_API_KEY` from the environment. The minimal configuration
mounts no Commands. Use `Ctrl+C` to exit. Construction errors are raised before the
terminal is started.

The default header uses generic Neuron AI branding. A title and subtitle can
be supplied when the terminal should identify a particular Agent or product:

```php
use NeuronTui\Tui;

Tui::make($agentFactoryRegistry, 'assistant', configurationStore: $configurationStore)
    ->setTitle('Research Agent')
    ->setSubtitle('Ask about the knowledge base')
    ->setFiglet('Research', 'slant')
    ->run();
```

`setFiglet()` adds an optional ASCII-art banner above the title. Its second
argument selects one of Symfony TUI's bundled fonts: `standard`, `big`,
`small`, `slant`, or `mini`.

The minimal and branding examples mount no Commands. Add them explicitly as
shown below. Configure each TUI before calling `run()`; an instance runs once.

## Commands

The TUI mounts no Commands by default. Add `/help` to list available commands
and `/exit` to close the terminal:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronTui\Tui;

$commands = new Commands([
    new HelpCommand(),
    new LeaveCommand(),
]);

Tui::make($agentFactoryRegistry, 'assistant', configurationStore: $configurationStore, commands: $commands)->run();
```

`HelpCommand` reads the mounted collection, including itself. Each standard
command accepts a custom slash-prefixed name: `new LeaveCommand('/quit')`
replaces `/exit` with `/quit`.

## Sessions

A Session is one conversation with the Agent. `ClearCommand` starts a fresh one
without leaving the terminal: the screen and the composer empty. A conversation
already managed by SessionStore remains stored. An external Agent History is not
imported when clearing.

`ResumeCommand` lists the current user's Sessions in the Picker, most recently
used first, each labelled with the first thing the person wrote in it. While
the list is open the composer takes no text: the arrow keys move through it,
typing narrows it, Enter chooses one and resumes it, and Escape leaves the
current one alone. Resuming displays that conversation; the Agent uses its
context for subsequent messages. A Session nobody wrote in is not listed.

The Conversation TUI reuses the supplied `SessionStore` instance. Default Stores
store managed conversations in memory for the life of the process and create
no directories or files. Startup keeps the Agent’s existing History; it does
not automatically register it with SessionStore or resume an earlier conversation.

To persist Sessions created by `/clear` and reopened by `/resume`, supply a
`SessionStore` backed by `FileStorage`. No stored Session is resumed automatically:

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\SessionStore;

$storage = new FileStorage(__DIR__ . '/.storage');
$configurationStore = new ConfigurationStore($storage, 'local-user');
$configurationStore->read('agent')
    ?? $configurationStore->create('agent', ['model' => 'gpt-5.4-nano']);

Tui::make(
    agentFactoryRegistry: $agentFactoryRegistry,
    initialAgentIdentifier: 'assistant',
    configurationStore: $configurationStore,
    sessionStore: new SessionStore($storage, 'local-user'),
    commands: new Commands([new ClearCommand(), new ResumeCommand()]),
)->run();
```

`SessionStore::create()` immediately stores an empty Session; `read($key)` returns
its History or `null`. A missing Resume selection leaves the current Agent active
and displays a warning. The default SessionStore uses in-memory storage owned by
`local`. Input history keeps its independent ownership model.

## Creating and replacing Agents

Tui requires an `AgentFactoryRegistry` and an `initialAgentIdentifier`; it no longer
accepts an Agent instance. Its constructor creates the initial Agent. It retains
the identifier and store for subsequent construction, including deferred selections.
An omitted configuration store is an empty in-memory store owned by `local`.

The selected class implements `ConfiguredAgentInterface::createAgent(ConfigurationStore): static`.
The method owns provider setup and reads whichever documents the application uses.
Neither `global` nor an `agent` field is required by the library. There is no separate
factory class or closure to register. Duplicate identifiers and invalid Agent
classes are rejected at registration.

Commands ask `$controls->createAgent()` for a fresh instance. They use `agent()` to
access the active instance and `useAgent()` to activate a prepared replacement:

```php
$agent = $controls->createAgent();
$agent->setChatHistory($controls->sessionStore()->create());
$controls->useAgent($agent);
```

Resume checks Session availability before construction. Opening the Picker creates
nothing; choosing later reads the current settings. To keep the current conversation
when changing an Agent's settings, assign `$controls->agent()->getChatHistory()`
to the replacement. Reusing the identical History object preserves notices.
Construction or History errors leave the previous Agent active; an already created
empty Session is not rolled back.

## Migrating Command integrations

Use `CommandControlsAdapterInterface` instead of `CommandAdapterInterface`.
Adapters expose `createAgent()` instead of `newAgent()` or `agentFactoryRegistry()`;
`configurationStore()` remains available for application commands. Register Agent
class names implementing the static creation interface, and replace the initial
Agent argument to Tui with registry and `initialAgentIdentifier`.

`Configuration` remains the document type. Its store exposes `create`, `read`,
`write` and `delete`; rename configuration `save()` calls to `write()`.
For a model change, validate the selection, read fresh settings, change the model,
write the document, create the replacement, attach the current History and activate.
If creation or History assignment fails, the settings remain saved and the old
Agent remains active. No automatic rollback is performed.

The demo owns its `agent` document and validates its supported model identifiers.
Its Agent creates a persistent initial Session lazily through Neuron's History hook,
using the configuration document's owner and `sessionStoragePath` (defaulting to
`examples/.storage`). Keep that path aligned with the SessionStore supplied to Tui.
Clear and Resume assign History before the fallback is needed, so they create no
extra startup Session.
The shared library imposes no configuration schema or document naming convention.
This source-incompatible contract requires the coordinated
`dev-feat/command-agent-factories` Neuron Interaction branch.

## Input history

Submitted messages and Commands share one ordered Input history per configured
Storage, across Sessions and Adapters. Blank submissions are ignored and only
consecutive exact duplicates collapse. Generated Agent prompts are excluded.
The InputHistory instance owns the navigation cursor and draft. In the TUI,
from an empty composer, ↑ recalls
older inputs, ↓ moves toward newer ones and restores the empty draft past the
newest input. Editing a recalled input leaves navigation. A Picker or Command
suggestions owns the arrow keys while its list is active.

By default, Input history lasts for the current process. To keep it between
runs, pass an `InputHistory` backed by `FileStorage`. Building on the Sessions
example, add input history and optional commands to the same composition:

```php
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;

Tui::make(
    agentFactoryRegistry: $agentFactoryRegistry,
    initialAgentIdentifier: 'assistant',
    configurationStore: $configurationStore,
    sessionStore: new SessionStore($storage, 'local-user'),
    inputHistory: new InputHistory($storage),
    commands: new Commands([
        new ClearCommand(),
        new ResumeCommand(),
        new HelpCommand(),
        new LeaveCommand(),
    ]),
)->run();
```

## Custom commands

Implement `CommandInterface` to add your own behavior. This command sends the
staged Git diff to the Agent for review:

```php
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronTui\Tui;

final class ReviewCommand implements CommandInterface
{
    public function name(): string
    {
        return '/review';
    }

    public function describe(): string
    {
        return 'Reviews what is staged in git.';
    }

    /** @param CommandControlsAdapterInterface<mixed> $controls */
    public function run(CommandControlsAdapterInterface $controls, CommandArguments $arguments): void
    {
        $diff = shell_exec('git diff --staged') ?: '';

        if (trim($diff) === '') {
            $controls->warn('Nothing staged to review.');

            return;
        }

        $controls->promptAgent("Review this diff:\n\n" . $diff);
    }
}

Tui::make($agentFactoryRegistry, 'assistant', configurationStore: $configurationStore, commands: new Commands(new ReviewCommand()))->run();
```

## Demo

`examples/` is a standalone Composer project acting as a Host Application. It
connects the Conversation TUI to OpenAI or Anthropic and consumes this library
through local path repositories. Check out `neuron-interaction` on `0.9.x`
beside this repository, including the matching Agent Adapter changes. Install its dependencies, create the local
environment file, add the credentials for the providers you want to use, then
start it:

```bash
cd examples
composer install
cp .env.example .env
# Edit .env
php demo.php
```

## Development

A fresh checkout needs the Composer dependencies and the agent skills, which
are restored from `skills-lock.json`:

```bash
# Use the matching sibling checkout until neuron-interaction 0.9.x is published.
cp composer.json composer.neuron4.local.json
COMPOSER=composer.neuron4.local.json composer config repositories.neuron-interaction path ../neuron-interaction
COMPOSER=composer.neuron4.local.json composer update
npx skills experimental_install
```

Then:

```bash
composer test
composer stan
composer --working-dir=examples install
```

The automated suite uses Neuron AI's fake provider and Symfony TUI's virtual
terminal. It requires no credentials and makes no network requests.

## License

Neuron TUI is released under the MIT License.

The demo is covered by the root test suite, including a local deterministic
Model → Clear → Resume → restart scenario. Analyse its application code explicitly
with `vendor/bin/phpstan analyse -c examples/phpstan.neon` after installing the
demo dependencies in `examples/`.
