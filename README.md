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

$commands = new Commands([
    new HelpCommand(),
    new LeaveCommand(),
]);

Tui::make($agent, commands: $commands)->run();
```

Each standard command accepts a custom slash-prefixed name: `new LeaveCommand('/quit')`
replaces `/exit` with `/quit`.

## User-message processors

Register application-specific preparation and presentation before `run()`:

```php
$tui = Tui::make($agent, commands: $commands);
$tui->addUserMessageProcessor($processor);
$tui->addUserMessageProcessor([$anotherProcessor, $lastProcessor]);
$tui->run();
```

Each processor implements `NeuronTui\UserMessageProcessorInterface`:

- `forAgent(string $input): string` prepares an ordinary user submission before
  it is queued. Processors run in registration order, each receiving the previous
  result. Throwing rejects the submission, shows the error and keeps the draft.
  An empty final result is also rejected.
- `forDisplay(string $content): string` presents user text in reverse registration
  order, including queued messages and loaded History. It must handle arbitrary
  text, including old messages it did not prepare, without side effects.

Input history retains the text as typed; the Agent's History retains the prepared
content. Assistant messages and tool output are not processed. Commands bypass
`forAgent()`, including prompts they produce, but user messages produced by
commands still use `forDisplay()`.

With no processors registered, text passes through unchanged. Neuron TUI does
not interpret skill markup: applications that previously relied on compact
`<skill>` presentation must register their own processor. This also controls
presentation of existing sessions without rewriting them.

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

$commands = new Commands([
    new ClearCommand(),
    new ResumeCommand()
]);

Tui::make(
    $agent,
    commands: $commands,
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
The [model example](examples/bin/model.php) remembers the model chosen with `/model`.

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

## Stop a response

Share a `StopSignal` between the provider's HTTP client and the TUI to stop
streaming with Escape. Install `amphp/http-client` in your application, then
configure the provider with this client:

```php
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronInteraction\Http\StoppableHttpClient;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;

use function Amp\delay;

$stopSignal = new StopSignal(new InMemoryStorage(), 'chatKey');

$client = new StoppableHttpClient(
    inner: new AmpHttpClient(),
    stopSignal: $stopSignal,
    onPoll: function (): void {
        delay(0);
    },
);

$agent->setAiProvider(new OpenAIResponses(
    key: $apiKey,
    model: $model,
    httpClient: $client,
));

Tui::make($agent)
    ->setStopSignal($stopSignal)
    ->run();
```

`onPoll` lets the TUI process keyboard input while streaming. The TUI clears
the signal before each turn; Escape requests a stop, and Neuron finalizes the
partial response. This does not cancel local tools or guarantee remote generation
has stopped. Use a distinct signal key for concurrent responses.

See [stop.php](examples/bin/stop.php) for the complete example.

## Custom commands

Implement `CommandInterface` to add your own behavior. This command sends the
staged Git diff to the Agent for review:

```php
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
    public function run(CommandAdapterInterface $adapter, string $value): void
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

## Examples

Install the example dependencies and set `OPENAI_API_KEY` in `.env`:

```bash
cd examples
composer install
cp .env.example .env
# Edit .env
```

| Example | What it shows | Run from `examples/` |
| --- | --- | --- |
| [basic.php](examples/bin/basic.php) | An Agent and the TUI. | `php bin/basic.php` |
| [stop.php](examples/bin/stop.php) | Experimental opt-in HTTP EOF with automatic Neuron history persistence. | `php bin/stop.php` |
| [sessions.php](examples/bin/sessions.php) | Saved conversations with `/clear` and `/resume`. | `php bin/sessions.php` |
| [model.php](examples/bin/model.php) | Model selection with `/model`, remembering the choice between runs. Conversation stays in memory. | `php bin/model.php` |
| [full.php](examples/bin/full.php) | Sessions, model selection, input history, response stop, tools and a custom header. | `php bin/full.php` |

Each example runs on its own. Model and Full also offer Anthropic through
`/model` when `ANTHROPIC_API_KEY` is configured. Use `Ctrl+C` to exit.

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

The automated suite uses Neuron AI's fake provider, real provider parsers with
HTTP fixtures, and Symfony TUI's virtual terminal. It requires no credentials
and makes no network requests.

## License

Neuron TUI is released under the MIT License.
