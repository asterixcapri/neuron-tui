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

The `0.8.x` branch supports Neuron AI 3.

Run this command in your application's directory:

```bash
composer require asterixcapri/neuron-tui
```

Composer also installs Neuron Interaction and the other required dependencies.

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
directly as the Agent's Chat History:

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\SessionStore;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');

$agent->setChatHistory($sessionStore->create()); // Or read and check an explicitly chosen key.

Tui::make(
    $agent,
    commands: new Commands([new ClearCommand(), new ResumeCommand()]),
    sessionStore: $sessionStore,
)->run();
```

`create()` immediately stores a new empty Session. `read($key)` returns a Session
or `null`; check for absence before installing it as the Agent's History.
No Session is resumed automatically. A missing Resume selection leaves the
current History installed and displays a warning.

Without a supplied Store, the TUI uses an in-memory SessionStore owned by `local`.
To choose another owner, supply a SessionStore configured by the Host Application.
Input history keeps its independent existing ownership model.

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
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');

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
    inputHistory: new InputHistory($storage),
)->run();
```

## Custom commands

Implement `CommandInterface` to add your own behavior. This command sends the
staged Git diff to the Agent for review:

```php
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\CommandAdapterInterface;
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

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
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

Commands communicate through `notify()`, `warn()` and `error()`. Neuron TUI
shows notices, yellow `Warning` labels and red `Error` labels respectively.
These methods do not stop a Command or change its technical execution status;
return explicitly when an expected failure prevents further work.

## Demo

`examples/` is a standalone Composer project acting as a Host Application. It
connects the Conversation TUI to OpenAI or Anthropic and consumes this library
through a local path repository. Install its dependencies, create the local
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
composer install
npx skills experimental_install
```

Then:

```bash
composer test
composer stan
composer --working-dir=examples install
```

See [Conversation modules](docs/conversation.md) for input handling, Turn
execution and choice presentation responsibilities.

The automated suite uses Neuron AI's fake provider and Symfony TUI's virtual
terminal. It requires no credentials and makes no network requests.

## License

Neuron TUI is released under the MIT License.

### Commands during a Turn

While the Agent is working, the TUI admits and suggests only Commands implementing
`NeuronInteraction\Command\ConcurrentCommandInterface`. Help and Leave already
implement it. Custom Commands may implement it too, provided they do not interfere
with state used by the active Agent work. Ordinary Commands are refused until
the Turn finishes, regardless of their names.
