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

Configure the Agent in your application, then pass it to `Tui`. Here,
`$provider` is your configured `NeuronAI\Providers\AIProviderInterface`
implementation:

```php
use NeuronAI\Agent\Agent;
use NeuronTui\Tui;

$agent = new Agent();
$agent->setAiProvider($provider);

Tui::make($agent)->run();
```

The minimal configuration displays the Agent’s existing conversation and accepts
new messages. Use `Ctrl+C` to exit.

The default header uses generic Neuron AI branding. A title and subtitle can
be supplied when the terminal should identify a particular Agent or product:

```php
use NeuronTui\Tui;

Tui::make($agent)
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

Tui::make($agent, commands: $commands)->run();
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

To persist the initial conversation, create a `FileStorage`, pass it to
`SessionStore` with the intended user identity, and install the new Session
directly as the Agent's Chat History. Register reproducible construction and read
the saved general configuration before creating the initial Agent:

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\SessionStore;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');
$configurationStore = new ConfigurationStore($storage, 'local-user');
$configuration = $configurationStore->read('global') ?? $configurationStore->create('global', [
    'agent' => 'assistant',
    'model' => 'my-model',
]);
$agentFactoryRegistry = new AgentFactoryRegistry();
$agentFactoryRegistry->register('assistant', static function (Configuration $configuration) use ($providerClient): MyAgent {
    $model = $configuration->get('model');
    if (!is_string($model) || $model === '') {
        throw new InvalidArgumentException('A model is required.');
    }

    return (new MyAgent($providerClient))->setModel($model);
});
$agent = $agentFactoryRegistry->create($configuration);

$agent->setChatHistory($sessionStore->create()); // Or read and check an explicitly chosen key.

Tui::make(
    $agent,
    commands: new Commands([new ClearCommand(), new ResumeCommand()]),
    sessionStore: $sessionStore,
    agentFactoryRegistry: $agentFactoryRegistry,
    configurationStore: $configurationStore,
)->run();
```

`create()` immediately stores a new empty Session. `read($key)` returns a Session
or `null`; check for absence before installing it as the Agent's History.
No Session is resumed automatically. A missing Resume selection leaves the
current History installed and displays a warning.

Without a supplied Store, the TUI uses an in-memory SessionStore owned by `local`.
To choose another owner, supply a SessionStore configured by the Host Application.
Input history keeps its independent existing ownership model.

## Creating and replacing Agents

`ClearCommand` and `ResumeCommand` read `global` from `configurationStore()` and ask
`agentFactoryRegistry()` to construct a fresh Agent. The registered closure owns
constructor dependencies, provider setup and setters. It receives a detached
Configuration; its `agent` field selects the registered identifier. Store durable
model and capability choices in Configuration so later construction reproduces them.
`MyAgent` and `$providerClient` above represent application-owned code and dependencies.

The TUI accepts these modules after its existing positional arguments, and shares
the same instances with every Command, including deferred Picker continuations.
Omitted modules remain empty in memory for the run; the TUI does not infer settings
from the initial Agent. Plain conversations and unrelated Commands need no factory.
Clear or Resume with missing configuration or a failed factory reports an ordinary Command
failure and keeps the previous Agent active.

`agent()` returns the live instance. `useAgent($agent)` activates the supplied
prepared instance and displays its History. Commands assign History explicitly:

```php
$configuration = $adapter->configurationStore()->read('global');
if ($configuration === null) {
    throw new RuntimeException('Missing global configuration.');
}
$agent = $adapter->agentFactoryRegistry()->create($configuration);
$agent->setChatHistory($adapter->sessionStore()->create());
$adapter->useAgent($agent);
```

To continue the same conversation, assign the current Agent's History instead.
The registry never assigns History or accesses storage. Failure before activation
keeps the previous Agent, although a Session created before History assignment
fails can remain stored.

Resume checks that the selected Session still exists before constructing an Agent.
Opening the Picker constructs nothing; selecting later rereads the current saved
configuration and Session availability. Session content never restores old settings.
Replacing an Agent with the identical History object preserves conversation notices.
The shared controls do not reconstruct the current Agent class or clone its settings.

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
example, this complete configuration shares one storage root for conversations
and submitted inputs, and adds `/help` and `/exit`:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');
$configurationStore = new ConfigurationStore($storage, 'local-user');
$configuration = $configurationStore->read('global') ?? $configurationStore->create('global', [
    'agent' => 'assistant',
    'model' => 'my-model',
]);
$agentFactoryRegistry = new AgentFactoryRegistry();
$agentFactoryRegistry->register('assistant', static function (Configuration $configuration) use ($providerClient): MyAgent {
    $model = $configuration->get('model');
    if (!is_string($model) || $model === '') {
        throw new InvalidArgumentException('A model is required.');
    }

    return (new MyAgent($providerClient))->setModel($model);
});
$agent = $agentFactoryRegistry->create($configuration);

$agent->setChatHistory($sessionStore->create());

$commands = new Commands([
    new ClearCommand(),
    new ResumeCommand(),
    new HelpCommand(),
    new LeaveCommand(),
]);

Tui::make(
    $agent,
    commands: $commands,
    sessionStore: $sessionStore,
    agentFactoryRegistry: $agentFactoryRegistry,
    configurationStore: $configurationStore,
    inputHistory: new InputHistory($storage),
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

    /** @param CommandControlsAdapterInterface<mixed> $adapter */
    public function run(CommandControlsAdapterInterface $adapter, CommandArguments $arguments): void
    {
        $diff = shell_exec('git diff --staged') ?: '';

        if (trim($diff) === '') {
            $adapter->warn('Nothing staged to review.');

            return;
        }

        $adapter->promptAgent("Review this diff:\n\n" . $diff);
    }
}

Tui::make($agent, commands: new Commands(new ReviewCommand()))->run();
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
