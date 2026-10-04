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

The `0.9.x` branch supports Neuron AI 4. The `0.8.x` branch supports Neuron AI 3.

Run this command in your application's directory:

```bash
composer require asterixcapri/neuron-tui
```

Composer also installs Neuron Interaction and the other required dependencies.

![Neuron TUI demo](docs/images/usage.gif)

## Usage

Configure the Agent in your application, then pass it to `Tui`. The TUI creates its Conversation when `run()` starts. Here,
`$provider` is your configured `NeuronAI\Providers\AIProviderInterface`
implementation:

```php
use NeuronAI\Agent\Agent;
use NeuronTui\Tui;

$agent = Agent::make();
$agent->setAiProvider($provider);

Tui::make($agent)
    ->run();
```

`Tui` starts a new conversation and accepts messages. Use `Ctrl+C` to exit.

### Branding

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

$commands = new Commands(
    new HelpCommand(),
    new LeaveCommand(),
);

Tui::make($agent)
    ->setCommands($commands)
    ->run();
```

Each standard command accepts a custom slash-prefixed name: `new LeaveCommand('/quit')`
replaces `/exit` with `/quit`.

## Custom commands

Implement `CommandInterface` to add your own behavior. This command sends the
staged Git diff to the Agent for review:

```php
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\CommandInterface;
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

    /** @param CommandContext $context */
    public function run(CommandContext $context, string $value): void
    {
        $diff = shell_exec('git diff --staged') ?: '';

        if (trim($diff) === '') {
            $context->notify('Nothing staged to review.', NotificationLevel::Warning);

            return;
        }

        $context->promptAgent(new UserMessage("Review this diff:\n\n" . $diff));
    }
}

Tui::make($agent)
    ->setCommands(new Commands(new ReviewCommand()))
    ->run();
```

Commands receive the library's concrete `CommandContext` and return `void`.
Use `notify($text, NotificationLevel::Info)` for feedback; Warning and Error
select the corresponding terminal presentation. `promptAgent()` registers a
UserMessage for execution in the same submission stream. Generated prompts are
saved in Session History and do not appear as newly typed human input.

The TUI constructs its Conversation with the supplied Commands and
ConfigurationStore. It consumes Notifications, SelectionRequests, ExitRequests
and Agent/Session changes alongside native Agent events. Choosing a picker
option submits CommandInput with the request's command and the opaque value;
Escape closes the picker without a submission.

While the Agent is responding, ordinary commands are unavailable. The terminal
keeps HelpCommand and LeaveCommand available, including their configured names.
The same policy governs suggestions and Conversation admission. ExitRequest
asks the terminal host to close; response interruption remains separate.

## Sessions

Use `/clear` to start a new conversation and `/resume` to return to a saved one.
In the session list, type to filter, use the arrow keys to move, Enter to select
and Escape to cancel.

To keep conversations between runs, supply a file-backed `SessionStore`:

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\SessionStore;
use NeuronTui\Tui;

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local-user');

$commands = new Commands(
    new ClearCommand($sessionStore),
    new ResumeCommand($sessionStore)
);

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->setCommands($commands)
    ->run();
```

Use a user identifier appropriate to your application in place of `local-user`.
SessionStore is optional. Without `setSessionStore()`, each TUI uses its own
in-memory Store with the local owner. Supply a persistent Store to keep sessions
between runs. ClearCommand and ResumeCommand receive their SessionStore in their
constructors. An explicit initial Session does not require setSessionStore().

Without `setSession()`, `run()` creates an empty Session in the configured or
default Store.
To reopen a conversation, read and validate the initial Session before starting:

```php
$session = $sessionStore->get($key);
if ($session === null) {
    throw new InvalidArgumentException('The requested session does not exist.');
}

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->setSession($session)
    ->setCommands($commands)
    ->run();
```

At startup, Tui passes the supplied Session directly to Conversation. Session
creation happens in Tui only when no initial Session is supplied. The host is
responsible for authorizing access to the Session before supplying it; startup
does not reload it from the configured Store. The two setters can be called in
either order before `run()`. An Agent that already contains messages requires an
explicit initial Session; that Session determines the conversation displayed and
continued by TUI. Configuring the TUI creates no Session and does not bind the
Agent. Startup creates the Conversation before entering the terminal event loop.

Session selection can replace the Agent instance. Commands retrieve the currently
selected Agent through `$context->agent()`; the host does not receive the internal
Conversation or an Agent accessor on Tui.

Commands use `useAgent($agent)` to change capabilities while keeping the current
Session, and `useSession($session)` to select another conversation while keeping
the Agent's configuration. Clear creates a new Session; Resume selects a saved
one. Both operate through the same Store.

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

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->setConfigurationStore($settings)
    ->run();
```

The fallback determines the expected type: use `read('retries', 3)` for an
integer, for example. Missing or incompatible values return the fallback;
string preferences must be non-empty. Writes save immediately.

Custom commands access these preferences through `$context->configurationStore()`.
The [model example](examples/bin/model.php) remembers the model chosen with `/model`.

## Input history

From an empty input, use ↑ and ↓ to recall earlier messages and commands.
By default, input history lasts for the current run. To keep it between runs:

```php
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;

$inputHistory = new InputHistory(new FileStorage(__DIR__ . '/.storage'));

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->setInputHistory($inputHistory)
    ->run();
```

Configure input history, settings and commands with `setInputHistory()`,
`setConfigurationStore()` and `setCommands()` before `run()`. Omitted input history
and settings use independent in-memory storage. Every setter is fluent and rejects
changes once startup begins; each TUI instance can run only once.

## Stop a response

Share a `StopSignal` between the provider's HTTP client and the TUI to stop
streaming with Escape. Install `amphp/http-client` in your application, then
configure the provider with this client:

```php
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Storage\InMemoryStorage;
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

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->setStopSignal($stopSignal)
    ->run();
```

The TUI clears the signal before each turn; Escape requests a stop, and Neuron
finalizes the partial response. This does not cancel local tools or guarantee
remote generation has stopped. Use a distinct signal key for concurrent responses.

See [stop.php](examples/bin/stop.php) for the complete example.

## User message processors

A user message processor defines preparation for the Agent and projection for
display. Implement `NeuronInteraction\Message\UserMessageProcessorInterface`:
`forAgent()` prepares submitted messages before they are sent.
`forDisplay()` projects all user messages for presentation,
including live human input, queued messages, and resumed History. Command prompts
receive a display projection when their saved History is shown.

```php
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronTui\Tui;

$processors = (new UserMessageProcessors())->addProcessor([
    new FileReferenceProcessor(__DIR__),
]);

Tui::make($agent)
    ->setSessionStore($sessionStore)
    ->setUserMessageProcessors($processors)
    ->run();
```

The TUI shows the display projection immediately, clears the composer and queues
the original input. When its turn starts, `sendInput()` applies preparation
once and returns the stream. Command-generated prompts execute in their existing
submission stream; processors
preserve recognized expanded content. Saved messages are never changed by display
projection. If preparation fails, the preview remains visible with an error and
the original input returns to an empty composer without replacing a newer draft. Input recall stores
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
reservation and Amp scheduling through its internal `TurnScheduler`.
`TurnRunner` calls `Conversation::sendInput()` and consumes the returned
stream in one loop, routing interaction events and native Neuron chunks. Its
`TurnRenderer` handles text and tool presentation without dispatching events.
The core Conversation owns preparation, command dispatch, ordered requests,
Session/Agent binding and native streaming. Queuing and busy-time admission remain
host policies, like a React frontend making sequential streaming POST requests.

The scheduler queues all messages with `enqueueMessage(UserMessage $message)`.
Display projection applies to every queued message. Its `tick()` prepares and runs
one turn at a time; `isBusy()` includes both queued messages and active turn work.

Preparation of pending input is deferred until its turn. A rejected queued input
is reported and restored to an empty composer; a newer draft is preserved and
the original remains in Input history. Errors and supported response stops
advance the queue without retries, preserving terminal behavior.

## Commands API migration

See [the migration guide](docs/commands-migration.md) for unified input, ordered
requests, selection responses, error propagation and the terminal admission policy.
Command prompts execute inside Conversation's stream; the host presents events
and retains scheduling of human inputs.
