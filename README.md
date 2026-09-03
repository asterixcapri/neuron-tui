# Neuron TUI

Neuron TUI is a reusable Conversation TUI for
[Neuron AI](https://github.com/neuron-core/neuron-ai). A Host Application
supplies a configured Agent; this library owns only the interactive terminal
experience.

## Installation

```bash
composer require asterixcapri/neuron-tui
```

Neuron TUI requires PHP 8.4.1 or newer and an interactive TTY.

## Usage

Configure the Agent in your application, then pass it to `Tui`:

```php
use NeuronAI\Agent\Agent;
use NeuronTui\Tui;

$agent = new Agent();
$agent->setAiProvider($provider);

Tui::make($agent)->run();
```

The default header uses generic Neuron AI branding. A title and subtitle can
be supplied when the terminal should identify a particular Agent or product:

```php
Tui::make($agent)
    ->setTitle('Research Agent')
    ->setSubtitle('Ask about the knowledge base')
    ->setFiglet('Research', 'slant')
    ->run();
```

`setFiglet()` adds an optional ASCII-art banner above the title. Its second
argument selects one of Symfony TUI's bundled fonts: `standard`, `big`,
`small`, `slant`, or `mini`.

A terminal built this way chats and nothing else: no Command is mounted
unless the Host Application names it, so every name typed after a slash is
unknown, and `Ctrl+C` is the way out.

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

The demo reads `examples/.env` through Symfony Dotenv. Existing process
environment variables take precedence over values from `examples/.env`. It
starts with an inexpensive OpenAI model; `/model` opens a Picker that can also
switch to other OpenAI and Anthropic models. It mounts the commands this
library ships, so `/exit` or Ctrl+C closes it and `/help` lists them.

## Commands

The Conversation TUI mounts nothing on its own. A Host Application mounts the
commands it wants — its own, and the ones this library ships — so the terminal
does what its application needs:

```php
use NeuronTui\Conversation\Controls;
use NeuronTui\Command\CommandInterface;

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

    public function run(Controls $controls, string $arguments): void
    {
        $diff = shell_exec('git diff --staged') ?: '';

        if (trim($diff) === '') {
            $controls->warn('Nothing staged to review.');

            return;
        }

        $controls->ask("Review this diff:\n\n" . $diff);
    }
}

Tui::make($agent)->addCommand(new ReviewCommand())->run();
```

A command answers to a name, slash included, and describes itself in one
line. Whatever is typed after the name reaches it as its arguments, empty when
nothing was typed. No name is reserved. If commands share a name, the first
one added receives matching input and every duplicate remains visible in
suggestions.

Once a command has run, the Conversation TUI reads the History back from the
Agent and repaints the screen when the command replaced it with another one. So
a command that opened another conversation — or handed the Agent a History of
its own — need say nothing about it, and a command that left the conversation
where it found it leaves the screen alone too, messages appended to it
included.

While it runs, a command is handed the **Controls**, and nothing else: the
widgets of the Conversation TUI stay out of reach.

- `say()` writes a line in the conversation.
- `warn()` writes one that reports something did not go as it should.
- `ask()` puts a prompt to the Agent as though the person had written it, and
  finishes: the answer arrives on the screen, not back in the command.
- `choose()` offers a titled, optionally described, ordered list of
  `ChoiceOption` values — each separating the returned key from the visible
  label — and waits there for the key of the line the person chose, or nothing
  at all if they cancelled. It is the one verb that waits, and the terminal
  goes on painting while the list is open.
- `agent()` returns the Agent, so its provider, instructions, tools and
  History change through the Neuron AI API rather than through verbs here.
- `commands()` returns the commands mounted on this terminal, the one asking
  included, so a command can list what may be typed here.
- `sessions()` returns the live Sessions owned by this terminal, so a command
  can start or resume a conversation without constructing parallel state.
- `useAgent()` puts another Agent in charge of answering from here on. The
  conversation under way moves over with it: the new Agent is handed the
  History the old one was answering, nothing changes on the screen, and it is
  the next answer that comes from elsewhere. A command that knows the two are
  not interchangeable installs a fresh History on the new Agent afterwards.
- `stop()` leaves the terminal.

A command that fails leaves the exception as a line of error in the
conversation, exactly as a failing turn does, and the terminal stays usable.

While a name is being written after a slash, the mounted commands are shown
above the composer, each with the line it describes itself with. Nothing is
mounted or configured to get them: they are the commands the Host Application
already mounted, so a terminal without commands shows none. The keys stay with
the composer meanwhile, and the ones that mean something there are taken: ↑↓
choose a line, Enter writes the chosen full name and runs it immediately, Tab
writes the chosen name and a space so what follows is typed as arguments, and
Escape takes the list away leaving the draft where it was. The list goes as
soon as the draft stops being a name: a space, a line break, or the slash
deleted. A slash in the middle of a message shows nothing and stays text for
the Agent.

### Concurrent commands

A command is refused while the Agent is answering, and can be typed again once
the turn has finished: one that replaced the conversation meanwhile would have
the answer on its way land where it does not belong. A command whose
synchronous run may overlap a Turn says so by implementing
`NeuronTui\Command\ConcurrentCommandInterface` instead of
`NeuronTui\Command\CommandInterface`, and is carried out at any time.

```php
use NeuronTui\Conversation\ConcurrentControls;
use NeuronTui\Command\ConcurrentCommandInterface;

final class Version implements ConcurrentCommandInterface
{
    public function name(): string
    {
        return '/version';
    }

    public function describe(): string
    {
        return 'Says which build is answering.';
    }

    public function run(ConcurrentControls $controls, string $arguments): void
    {
        $controls->say('Build ' . MyApp::VERSION);
    }
}
```

Both interfaces carry the name, description and `run()` contract; what changes
is what `run()` is handed. In exchange for overlapping a Turn, such a command
receives the **ConcurrentControls**: only
`say()`, `warn()`, `commands()` and `stop()`. No `choose()`, because nobody
should be picking from a list while answers and tool calls scroll underneath;
no `agent()`, no `ask()` and no `useAgent()`, because the Agent is busy
answering. The restriction is in the type, so a Picker opened from such a
command does not compile rather than being merely discouraged.

`LeaveCommand` and `HelpCommand` are the two shipped commands that run this
way: leaving and reading what may be typed here change no conversation.

### The commands this library ships

Four commands come with Neuron TUI, and they are mounted like any other. Each
takes the name it answers to at construction, so a Host Application that
prefers `/quit` to `/exit` writes no command of its own:

| Class | Default name | What it does |
| --- | --- | --- |
| `NeuronTui\Command\ClearCommand` | `/clear` | Starts a new Session, leaving the current one stored. |
| `NeuronTui\Command\ResumeCommand` | `/resume` | Lets you choose a stored Session to resume. |
| `NeuronTui\Command\LeaveCommand` | `/exit` | Closes the Conversation TUI. Concurrent. |
| `NeuronTui\Command\HelpCommand` | `/help` | Lists what can be typed here. Concurrent. |

```php
use NeuronTui\Command\ClearCommand;
use NeuronTui\Command\HelpCommand;
use NeuronTui\Command\LeaveCommand;
use NeuronTui\Command\ResumeCommand;

Tui::make($agent)->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new LeaveCommand('/quit'),
    new HelpCommand(),
])->run();
```

The two commands that touch Sessions reach the live instance owned by the
Conversation TUI through their Controls. The Host Application does not create
a parallel Sessions dependency or install a History on the Agent.
`HelpCommand` receives no list of commands: the one it shows contains itself,
so the Conversation TUI hands it over while it runs rather than the Host
Application building it beforehand. Typing `/help` lists every mounted command
with the line it describes itself by, the ones the Host Application wrote
included.

Leave any of the four out and the terminal simply does not answer to that name.
Without `LeaveCommand` there is no Command to close the terminal, and
`Ctrl+C` is the only way out.

### Command kits

A **Command kit** is a group of commands mounted in one line. `SessionKit` is
the one this library ships, grouping both commands that touch Sessions.

```php
use NeuronTui\Command\LeaveCommand;
use NeuronTui\Command\SessionKit;
use NeuronTui\Storage\FileStorage;

$storage = new FileStorage('/var/lib/my-app');

Tui::make($agent)
    ->setStorage($storage)
    ->addCommand([new SessionKit(), new LeaveCommand()])
    ->run();
```

A kit can be taken with some of its commands left out, or with only the named
ones kept, so an application in which conversations are not thrown away has
`/resume` without `/clear`:

```php
use NeuronTui\Command\ClearCommand;

Tui::make($agent)->addCommand([
    (new SessionKit())->exclude([ClearCommand::class]),
    new LeaveCommand(),
])->run();

// The other way round, keeping only what is named:
Tui::make($agent)
    ->addCommand((new SessionKit())->only([ClearCommand::class]))
    ->run();
```

Both answer with a kit of their own and leave the one asked untouched, so the
same kit can be mounted twice, differently. A kit is unrolled when it is added:
afterwards a command that arrived in a kit and one mounted on its own are the
same thing, with the same listing and rules.

A Host Application groups commands of its own by extending
`NeuronTui\Command\AbstractCommandKit` and naming them in `provide()`; the
`NeuronTui\Command\CommandKitInterface` interface is what the Conversation TUI
mounts. Renaming a command stays the command's own business, so a kit is the
short way in and writing its commands out by hand remains the way to give them
names of one's own.

## Sessions

A Session is one conversation with the Agent. `ClearCommand` starts a fresh one
without leaving the terminal: the screen and the composer empty, and the
conversation that was on screen stays where it is stored.

`ResumeCommand` lists the Sessions of this Agent in the Picker, most recently
used first, each labelled with the first thing the person wrote in it. While
the list is open the composer takes no text: the arrow keys move through it,
typing narrows it, Enter chooses one and resumes it, and Escape leaves the
current one alone. Resuming paints that conversation and the Agent answers
with its context. A Session nobody wrote in is not listed.

The Conversation TUI owns one live `Sessions` instance. By default it stores
everything in memory for the life of the process and creates no directories or
files. To persist conversations, the Host Application configures one shared
storage directory and explicitly mounts the Session commands it wants:

```php
use NeuronTui\Command\SessionKit;
use NeuronTui\Storage\FileStorage;

$storage = new FileStorage('/var/lib/my-app');

Tui::make($agent)
    ->setStorage($storage)
    ->addCommand(new SessionKit())
    ->run();
```

`FileStorage` separates each TUI-owned namespace beneath that root. The Host
Application neither implements Neuron AI's `ChatHistoryInterface` nor installs
a Session History on the Agent: the runtime starts its initial Session and
owns History composition. Neuron TUI never deletes a stored conversation.

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

Neuron TUI is released under the MIT License.
