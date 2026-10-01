# Neuron TUI

A ready-to-use terminal UI for working with or testing your
[Neuron AI](https://github.com/neuron-core/neuron-ai) agent. Manage conversation
sessions, add commands, and recall previous inputs—all from the terminal.

Built with [Symfony TUI](https://github.com/symfony/tui).

Sessions, commands, and input history are powered by
[Neuron Chat Core](https://github.com/asterixcapri/neuron-chat-core), so you
can use the same features in backend applications too.

Requires PHP 8.4.1+ and an interactive terminal.

## Installation

The `0.9.x` branch supports Neuron AI 4. The `0.8.x` branch supports Neuron AI 3.

Run this command in your application's directory:

```bash
composer require asterixcapri/neuron-tui
```

Composer also installs Neuron Interaction and the other required dependencies.

![Neuron TUI demo](docs/images/usage.gif)

## Usage

Configure the Agent in your application, compose a core conversation runtime,
then pass that runtime to `Tui`. Here,
`$provider` is your configured `NeuronAI\Providers\AIProviderInterface`
implementation:

```php
use NeuronAI\Agent\Agent;
use NeuronChatCore\Conversation\ConversationRuntime;
use NeuronTui\Tui;

$agent = Agent::make();
$agent->setAiProvider($provider);
$runtime = new ConversationRuntime($agent);

Tui::make($runtime)->run();
```

`Tui` starts a new conversation and accepts messages. Use `Ctrl+C` to exit.

### Branding

The default header uses generic Neuron AI branding. A title and subtitle can
be supplied when the terminal should identify a particular Agent or product:

```php
use NeuronTui\Tui;

Tui::make($runtime)
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
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Command\HelpCommand;
use NeuronChatCore\Command\LeaveCommand;
use NeuronTui\Tui;

$commands = (new Commands())->addCommand([
    new HelpCommand(),
    new LeaveCommand(),
]);

Tui::make($runtime, commands: $commands)->run();
```

Each standard command accepts a custom slash-prefixed name: `new LeaveCommand('/quit')`
replaces `/exit` with `/quit`.

## Custom commands

Implement `CommandInterface` to add your own behavior. This command sends the
staged Git diff to the Agent for review:

```php
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Command\CommandAdapterInterface;
use NeuronChatCore\Command\CommandInterface;
use NeuronAI\Chat\Messages\UserMessage;
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
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $diff = shell_exec('git diff --staged') ?: '';

        if (trim($diff) === '') {
            $adapter->warn('Nothing staged to review.');

            return;
        }

        $adapter->promptAgent(new UserMessage("Review this diff:\n\n" . $diff));
    }
}

Tui::make($runtime, commands: (new Commands())->addCommand(new ReviewCommand()))->run();
```

Commands communicate through `notify()`, `warn()` and `error()`. Neuron TUI
shows notices, yellow `Warning` labels and red `Error` labels respectively.
Return from your command after reporting an error if it cannot continue.

While the Agent is responding, ordinary commands are unavailable. Commands that
can safely run during a response may implement
`NeuronChatCore\Command\ConcurrentCommandInterface`; Help and Leave already do.

## Sessions

Use `/clear` to start a new conversation and `/resume` to return to a saved one.
In the session list, type to filter, use the arrow keys to move, Enter to select
and Escape to cancel.

To keep conversations between runs, configure a file-backed `SessionStore` and
pass an initial Session from it:

```php
use NeuronChatCore\Command\ClearCommand;
use NeuronChatCore\Command\ResumeCommand;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Storage\FileStorage;
use NeuronChatCore\Session\SessionStore;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');

$session = $sessionStore->create();

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand()
]);

Tui::make(
    new \NeuronChatCore\Conversation\ConversationRuntime($agent, $sessionStore, session: $session),
    commands: $commands,
)->run();
```

Use a user identifier appropriate to your application in place of `local-user`.
By default, Sessions last only for the current run. Session selection can replace
the Agent instance; use `$tui->agent()` to retrieve the currently selected Agent.

Every runtime conversation belongs to its SessionStore from construction.
Without `session`, the runtime creates an empty Session in the supplied Store,
or in its default in-memory Store. To reopen a conversation, pass `session: $sessionStore->read($key)` after
checking that it exists. Pass the matching SessionStore and Session to the runtime. An Agent that already contains messages requires an explicit Session;
that Session determines the conversation displayed and continued by TUI.

Commands use `useAgent($agent)` to change capabilities while keeping the current
Session, and `useSession($session)` to select another conversation while keeping
the Agent's configuration. Clear creates a new Session; Resume selects a saved
one. Both operate through the same Store.

## Configuration

Use `ConfigurationStore` to remember application preferences, such as the
selected model:

```php
use NeuronChatCore\Configuration\ConfigurationStore;
use NeuronChatCore\Storage\FileStorage;
use NeuronTui\Tui;

$settings = new ConfigurationStore(new FileStorage(__DIR__ . '/.storage'), 'local-user');
$model = $settings->read('model', 'openai:gpt-5.4-nano');
$settings->write('model', 'openai:gpt-5.4-mini');

Tui::make($runtime, configurationStore: $settings)->run();
```

The fallback determines the expected type: use `read('retries', 3)` for an
integer, for example. Missing or incompatible values return the fallback;
string preferences must be non-empty. Writes save immediately.

Custom commands access these preferences through `$adapter->configurationStore()`.
The [model example](examples/bin/model.php) remembers the model chosen with `/model`.

## Input history

From an empty input, use ↑ and ↓ to recall earlier messages and commands.
By default, input history lasts for the current run. To keep it between runs:

```php
use NeuronChatCore\InputHistory\InputHistory;
use NeuronChatCore\Storage\FileStorage;
use NeuronTui\Tui;

$inputHistory = new InputHistory(new FileStorage(__DIR__ . '/.storage'));

Tui::make($runtime, inputHistory: $inputHistory)->run();
```

You can pass `inputHistory`, `configurationStore` and `commands`
together in the same `Tui::make()` call.

## Stop a response

Share a `StopSignal` between the provider's HTTP client and the TUI to stop
streaming with Escape. Install `amphp/http-client` in your application, then
configure the provider with this client:

```php
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronChatCore\Interruption\StopSignal;
use NeuronChatCore\Storage\InMemoryStorage;
use NeuronTui\Tui;

use function Amp\delay;

$stopSignal = new StopSignal(new InMemoryStorage(), 'chatKey');

$client = new StoppableHttpClient(
    client: new AmpHttpClient(),
    shouldStop: $stopSignal->stopCallback(
        // Yield to the event loop so the TUI can read Escape while streaming.
        onPoll: function (): void {
            delay(0);
        },
    ),
);

$agent->setAiProvider(new OpenAIResponses(
    key: $apiKey,
    model: $model,
    httpClient: $client,
));

Tui::make(new \NeuronChatCore\Conversation\ConversationRuntime($agent, stopSignal: $stopSignal))
    ->run();
```

The TUI clears the signal before each turn; Escape requests a stop, and Neuron
finalizes the partial response. This does not cancel local tools or guarantee
remote generation has stopped. Use a distinct signal key for concurrent responses.

See [stop.php](examples/bin/stop.php) for the complete example.

## User message processors

A user message processor changes what the Agent receives without changing what
the user sees. Implement `NeuronChatCore\Message\UserMessageProcessorInterface`:
`forAgent()` prepares submitted messages before they are sent. Live human input
is shown immediately as written. `forDisplay()` projects resumed History and
Command-generated prompt previews.

```php
use NeuronChatCore\Conversation\ConversationRuntime;
use NeuronChatCore\Message\UserMessageProcessors;
use NeuronTui\Tui;

$processors = (new UserMessageProcessors())->addProcessor([
    new FileReferenceProcessor(__DIR__),
]);

$runtime = new ConversationRuntime($agent, $sessionStore, userMessageProcessors: $processors);
Tui::make($runtime)->run();
```

The TUI shows original input immediately, clears the composer and queues it.
When its turn starts, `submitMessage()` applies preparation once and returns the
native stream. Command-generated prompts use the same submission API; processors
preserve recognized expanded content. Saved messages are never changed by display
projection. If preparation fails, the original remains visible with an error and
returns to an empty composer without replacing a newer draft. Input recall stores
the original submitted input. Reloaded messages use `forDisplay()` on the saved
Agent History, so transformed content or attachments can have a different preview.

See [messages.php](examples/bin/messages.php) for the complete example.

## Examples

Install the example dependencies and set `OPENAI_API_KEY` in `.env`, plus
`ANTHROPIC_API_KEY` to choose Anthropic models with `/model`:

```bash
cd examples
composer install
cp .env.example .env
# Edit .env
```

| Example | What it shows | Run from `examples/` |
| --- | --- | --- |
| [basic.php](examples/bin/basic.php) | An Agent and the TUI. | `php bin/basic.php` |
| [sessions.php](examples/bin/sessions.php) | Saved conversations with automatic titles, `/clear` and `/resume`. | `php bin/sessions.php` |
| [model.php](examples/bin/model.php) | Model selection with `/model`, remembering the choice between runs. Conversation stays in memory. | `php bin/model.php` |
| [stop.php](examples/bin/stop.php) | Stopping a streaming response with Escape. | `php bin/stop.php` |
| [messages.php](examples/bin/messages.php) | File references expanded for the Agent while displaying the original input. | `php bin/messages.php` |
| [full.php](examples/bin/full.php) | Sessions with automatic titles, message processing, model selection, input history, response stop, tools and a custom header. | `php bin/full.php` |

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

Run the example tests with:

```bash
vendor/bin/phpunit -c examples/phpunit.xml.dist
```

The automated suite uses Neuron AI's fake provider, real provider parsers with
HTTP fixtures, and Symfony TUI's virtual terminal. It requires no credentials
and makes no network requests.

## License

Neuron TUI is released under the MIT License.

## Execution and pending messages

The TUI is the terminal frontend. It owns the pending-input FIFO, local turn
reservation, Amp scheduling, command presentation and consumption of native
Neuron chunks through its internal ConversationController. The core
ConversationRuntime owns preparation, Session/Agent binding and native streaming;
it has no busy admission, queue or custom event protocol. This is
the same boundary as a React frontend making sequential streaming POST requests.

Preparation of pending input is deferred until its turn. A rejected queued input
is reported and restored to an empty composer; a newer draft is preserved and
the original remains in Input history. Errors and supported response stops
advance the queue without retries, preserving terminal behavior.
