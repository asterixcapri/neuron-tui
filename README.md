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
    ->run();
```

A terminal built this way chats and nothing else: no Slash command is mounted
unless the Host Application names it, so every name typed after a slash is
unknown, and `Ctrl+C` is the way out.

## Slash commands

The Conversation TUI mounts nothing on its own. A Host Application mounts the
commands it wants — its own, and the ones this library ships — so the terminal
does what its application needs:

```php
use NeuronTui\Conversation\Controls;
use NeuronTui\Conversation\SlashCommand;

final class Review implements SlashCommand
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

Tui::make($agent)->addCommand(new Review())->run();
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

### Commands that run while the Agent is working

A command is refused while the Agent is answering, and can be typed again once
the turn has finished: one that replaced the conversation meanwhile would have
the answer on its way land where it does not belong. A command that changes
nothing of the sort says so by implementing
`NeuronTui\Conversation\RunsWhileWorking` instead of
`NeuronTui\Conversation\SlashCommand`, and is carried out at any time.

```php
use NeuronTui\Conversation\LimitedControls;
use NeuronTui\Conversation\RunsWhileWorking;

final class Version implements RunsWhileWorking
{
    public function name(): string
    {
        return '/version';
    }

    public function describe(): string
    {
        return 'Says which build is answering.';
    }

    public function run(LimitedControls $controls, string $arguments): void
    {
        $controls->say('Build ' . MyApp::VERSION);
    }
}
```

Both interfaces extend `NeuronTui\Conversation\Command`, which carries the
name and the description; what changes is what `run()` is handed. In exchange
for running at any time, such a command receives the **LimitedControls**: only
`say()`, `warn()`, `commands()` and `stop()`. No `choose()`, because nobody
should be picking from a list while answers and tool calls scroll underneath;
no `agent()`, no `ask()` and no `useAgent()`, because the Agent is busy
answering. The restriction is in the type, so a Picker opened from such a
command does not compile rather than being merely discouraged.

`Leave` and `Help` are the two shipped commands that run this way: leaving and
reading what may be typed here change no conversation.

### The commands this library ships

Four commands come with Neuron TUI, and they are mounted like any other. Each
takes the name it answers to at construction, so a Host Application that
prefers `/quit` to `/exit` writes no command of its own:

| Class | Default name | What it does |
| --- | --- | --- |
| `NeuronTui\Conversation\Commands\Clear` | `/clear` | Starts a new Session, leaving the current one stored. |
| `NeuronTui\Conversation\Commands\Resume` | `/resume` | Lets you choose a stored Session to resume. |
| `NeuronTui\Conversation\Commands\Leave` | `/exit` | Closes the Conversation TUI. Runs while the Agent is working. |
| `NeuronTui\Conversation\Commands\Help` | `/help` | Lists what can be typed here. Runs while the Agent is working. |

```php
use NeuronTui\Conversation\Commands\Clear;
use NeuronTui\Conversation\Commands\Help;
use NeuronTui\Conversation\Commands\Leave;
use NeuronTui\Conversation\Commands\Resume;
use NeuronTui\Session\InMemorySessionProvider;

$sessions = new InMemorySessionProvider();
$agent->setChatHistory($sessions->start());

Tui::make($agent)->addCommand([
    new Clear($sessions),
    new Resume($sessions),
    new Leave('/quit'),
    new Help(),
])->run();
```

The two commands that touch the Sessions receive the Session provider, because
the place conversations live is named where it is needed. Passing the same
provider to both is what makes them agree on which conversations exist. `Help`
receives no list of commands: the one it shows contains itself, so the Conversation TUI
hands it over while it runs rather than the Host Application building it
beforehand. Typing `/help` lists every mounted command with the line it
describes itself by, the ones the Host Application wrote included.

Leave any of the four out and the terminal simply does not answer to that
name. Without `Leave` there is no Slash command to close the terminal, and
`Ctrl+C` is the only way out.

### Command kits

A **Command kit** is a group of commands mounted in one line, carrying between
them whatever they need to work. `SessionKit` is the one this library ships: it
is given the Session provider once and hands it to both the commands that
touch the Sessions.

```php
use NeuronTui\Conversation\Commands\Leave;
use NeuronTui\Conversation\Commands\SessionKit;
use NeuronTui\Session\FileSessionProvider;

$sessions = new FileSessionProvider('/var/lib/my-app/sessions');
$agent->setChatHistory($sessions->start());

Tui::make($agent)->addCommand([
    new SessionKit($sessions),
    new Leave(),
])->run();
```

A kit can be taken with some of its commands left out, or with only the named
ones kept, so an application in which conversations are not thrown away has
`/resume` without `/clear`:

```php
use NeuronTui\Conversation\Commands\Clear;

Tui::make($agent)->addCommand([
    (new SessionKit($sessions))->exclude([Clear::class]),
    new Leave(),
])->run();

// The other way round, keeping only what is named:
Tui::make($agent)
    ->addCommand((new SessionKit($sessions))->only([Clear::class]))
    ->run();
```

Both answer with a kit of their own and leave the one asked untouched, so the
same kit can be mounted twice, differently. A kit is unrolled when it is added:
afterwards a command that arrived in a kit and one mounted on its own are the
same thing, with the same listing and rules.

A Host Application groups commands of its own by extending
`NeuronTui\Conversation\AbstractCommandKit` and naming them in `provide()`; the
`NeuronTui\Conversation\CommandKit` interface is what the Conversation TUI
mounts. Renaming a command stays the command's own business, so a kit is the
short way in and writing its commands out by hand remains the way to give them
names of one's own.

## Sessions

A Session is one conversation with the Agent. `Clear` starts a fresh one
without leaving the terminal: the screen and the composer empty, and the
conversation that was on screen stays where it is stored.

`Resume` lists the Sessions of this Agent in the Picker, most recently used
first, each labelled with the first thing the person wrote in it. While the
list is open the composer takes no text: the arrow keys move through it,
typing narrows it, Enter chooses one and resumes it, and Escape leaves the
current one alone. Resuming paints that conversation and the Agent
answers with its context. A Session nobody wrote in is not listed.

Sessions come from a **Session provider**, which is an argument of the commands
that use it rather than of the Conversation TUI. `InMemorySessionProvider`
keeps them for the life of the process and writes nothing anywhere; keeping
them on disk, or anywhere else, is a different provider passed the same way:

```php
use NeuronTui\Session\FileSessionProvider;

$sessions = new FileSessionProvider('/var/lib/my-app/sessions');
$agent->setChatHistory($sessions->start());

Tui::make($agent)->addCommand([
    new Clear($sessions),
    new Resume($sessions),
])->run();
```

An application that keeps conversations in its own storage implements
`NeuronTui\Session\SessionProvider` instead. It answers three questions:
start a Session, list the Sessions, and resume one by its key. Starting and
resuming return the Neuron AI chat history that the Host Application or a
command installs on the Agent; saving, reloading and deserializing remain
Neuron AI's work. Neuron TUI never deletes a stored conversation.

Starting a Session replaces the History configured on the Agent by the Host
Application, because a provider builds every History it hands back. An
application that keeps its conversations somewhere passes the Session provider
reaching that place. See
[ADR 0001](docs/adr/0001-sessions-replace-the-agent-chat-history.md), and
[ADR 0002](docs/adr/0002-the-conversation-tui-mounts-nothing-on-its-own.md)
for why the provider is named on the commands instead of on the terminal.

`NeuronTui\Tui` is the public module, and two seams are the dependencies
an application may supply. The Session provider:
`NeuronTui\Session\SessionProvider` to implement,
`NeuronTui\Session\Session` to list a Session with, and
`NeuronTui\Session\InMemorySessionProvider` and
`NeuronTui\Session\FileSessionProvider` as the two shipped providers. And the
Slash commands it mounts: `NeuronTui\Conversation\SlashCommand` to implement,
`NeuronTui\Conversation\RunsWhileWorking` for a command that runs while the
Agent is answering, `NeuronTui\Conversation\Command` behind both,
`NeuronTui\Conversation\Controls` and `NeuronTui\Conversation\LimitedControls`
as what each is handed while it runs, and
`NeuronTui\Conversation\ChoiceOption` for each option offered by `choose()`,
`NeuronTui\Conversation\Commands\Clear`,
`NeuronTui\Conversation\Commands\Resume`,
`NeuronTui\Conversation\Commands\Leave` and
`NeuronTui\Conversation\Commands\Help` as the commands shipped ready to
mount, and `NeuronTui\Conversation\CommandKit`,
`NeuronTui\Conversation\AbstractCommandKit` and
`NeuronTui\Conversation\Commands\SessionKit` for mounting a group of them in
one line.
Every other class under the `NeuronTui` namespace is annotated `@internal`,
carries no stability promise, and may be renamed, split, or removed in any
release.

The Host Application remains responsible for constructing the Agent,
providers, credentials, tools, History persistence, and the script or
framework command that launches the interaction. Neuron TUI does not provide
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
variables take precedence over values from `examples/.env`. It mounts the
commands this library ships, so `/exit` or Ctrl+C closes it and `/help` lists
them.

## Keys

- Enter sends a message, or runs the selected Command suggestion while its
  list is open.
- Shift+Enter inserts a line break.
- Escape closes the Command suggestions while they are open, and clears the
  unsent draft otherwise.
- ↑↓ choose a line of the Command suggestions, Enter runs it, and Tab
  completes its name for arguments.
- PageUp and PageDown browse the History.
- Ctrl+C closes the Conversation TUI, mounted commands or not.
- Any command the Host Application mounted, by the name it answers to —
  including `Clear`, `Resume`, `Leave` and `Help` when it mounted them.

A command is refused while the Agent is working, so an arriving answer cannot
land in the wrong Session; a command that says in its type that it runs while
the Agent works — `Leave` and `Help` among the ones shipped — is carried out at
any time. Unknown Slash commands stay in the composer so they can be corrected
and are never sent to the Agent.

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
