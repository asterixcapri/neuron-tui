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

Add commands as shown below. Configure each TUI before calling `run()`;
an instance runs once.

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

Each standard command accepts a custom slash-prefixed name: `new LeaveCommand('/quit')`
replaces `/exit` with `/quit`.

## Sessions

Use `/clear` to start a new conversation and `/resume` to return to a saved one.
In the session list, type to filter, use the arrow keys to move, Enter to select
and Escape to cancel.

To keep conversations between runs, configure a file-backed `SessionStore` and
start the Agent with a Session from it:

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\SessionStore;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');

$agent->setChatHistory($sessionStore->create());

Tui::make(
    $agent,
    commands: new Commands([new ClearCommand(), new ResumeCommand()]),
    sessionStore: $sessionStore,
)->run();
```

Use a user identifier appropriate to your application in place of `local-user`.
By default, Sessions last only for the current run.

## Configuration

Use `ConfigurationStore` to remember application preferences, such as the
selected model:

```php
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;

$settings = new ConfigurationStore(new FileStorage(__DIR__ . '/.storage'), 'local-user');
$model = $settings->read('model', 'openai:gpt-5.4-nano');
$settings->write('model', 'openai:gpt-5.4-mini');

Tui::make($agent, configurationStore: $settings)->run();
```

The fallback determines the expected type: use `read('retries', 3)` for an
integer, for example. Missing or incompatible values return the fallback;
string preferences must be non-empty. Writes save immediately.

Custom commands access these preferences through `$adapter->configurationStore()`.
The [demo](examples/demo.php) uses this to remember the model chosen with `/model`.

## Input history

From an empty input, use ↑ and ↓ to recall earlier messages and commands.
By default, input history lasts for the current run. To keep it between runs:

```php
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;

$inputHistory = new InputHistory(new FileStorage(__DIR__ . '/.storage'));

Tui::make($agent, inputHistory: $inputHistory)->run();
```

You can pass `inputHistory`, `sessionStore`, `configurationStore` and `commands`
together in the same `Tui::make()` call.

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
Return from your command after reporting an error if it cannot continue.

While the Agent is responding, ordinary commands are unavailable. Commands that
can safely run during a response may implement
`NeuronInteraction\Command\ConcurrentCommandInterface`; Help and Leave already do.

## Demo

The demo connects the TUI to OpenAI or Anthropic. Install its dependencies,
create the environment file, add your provider credentials, then start it:

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
