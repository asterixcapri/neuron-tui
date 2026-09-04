# Neuron TUI

Neuron TUI is a reusable Conversation TUI for
[Neuron AI](https://github.com/neuron-core/neuron-ai). A Host Application
supplies a configured Agent; this library owns only the interactive terminal
experience.

## Installation

The extraction is available on `feat/extract-neuron-interaction` and has not
been released. Add both development dependencies and both repositories to
your application's root `composer.json`, then run `composer update`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/asterixcapri/neuron-tui"},
        {"type": "vcs", "url": "https://github.com/asterixcapri/neuron-interaction"}
    ],
    "require": {
        "asterixcapri/neuron-tui": "dev-feat/extract-neuron-interaction",
        "asterixcapri/neuron-interaction": "dev-feat/extract-neuron-interaction"
    }
}
```

Composer does not inherit repository definitions or stability permissions from
dependencies. Requiring both development versions at the root makes those
permissions explicit while keeping the default stable minimum for other
packages. The repositories and branch must be accessible to your installation.

Neuron TUI requires PHP 8.4.1 or newer and an interactive TTY.

Neuron TUI adapts the separate `asterixcapri/neuron-interaction` library. That
package owns Commands, Sessions, Input history and Storage and does not depend
on terminal code. Rendering, Agent turns, streaming and keyboard handling stay
in Neuron TUI. Input history also provides optional recall navigation.

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

The demo's root manifest declares Neuron Interaction's VCS repository and
development requirement explicitly. Its `dev-main` TUI constraint is a local
path version override for this checkout, not a claim that the extraction has
been merged into the remote main branch.

## Commands

The Conversation TUI mounts nothing on its own. A Host Application mounts the
commands it wants — its own, and the ones this library ships — so the terminal
does what its application needs:

```php
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandControlsInterface;
use NeuronInteraction\Command\CommandInterface;

final class ReviewCommand implements CommandInterface
{
    public function name(): string
    {
        return 'review';
    }

    public function describe(): string
    {
        return 'Reviews what is staged in git.';
    }

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
    {
        $diff = shell_exec('git diff --staged') ?: '';

        if (trim($diff) === '') {
            $controls->warn('Nothing staged to review.');

            return;
        }

        $controls->promptAgent("Review this diff:\n\n" . $diff);
    }
}

Tui::make($agent)->addCommand(new ReviewCommand())->run();
```

A Command's identifier is neutral: `review`. The terminal adds and parses the
slash, so the person types `/review`. Text after the name reaches the Command
as `CommandArguments::$text`, empty when no arguments were supplied. No name
is reserved. If commands share a name, the first
one added receives matching input and every duplicate remains visible in
suggestions.

Once a command has run, the Conversation TUI reads the History back from the
Agent and repaints the screen when the command replaced it with another one. So
a command that opened another conversation — or handed the Agent a History of
its own — need say nothing about it, and a command that left the conversation
where it found it leaves the screen alone too, messages appended to it
included.

While it runs, a Command receives `CommandControlsInterface`, implemented by
the TUI Adapter. Command-specific services belong in its constructor.

- `say()` writes a line in the conversation.
- `warn()` writes one that reports something did not go as it should.
- `promptAgent()` submits a generated prompt to the normal Agent flow and
  returns. The answer arrives through the TUI's turn and streaming machinery.
  Input history records the original Command submission, excluding that prompt.
- `requestSelection()` accepts a `NeuronInteraction\Command\SelectionRequest`
  and returns immediately. The TUI presents its `SelectionOption` values in the
  Picker. Choosing invokes the request's target Command again with the selected
  value as new `CommandArguments`; cancellation leaves the conversation alone.
  Controls do not retain a selected value.
- `agent()` returns the Agent, so its provider, instructions, tools and
  History change through the Neuron AI API rather than through verbs here.
- `commands()` returns the shared `Commands` collection; `all()` enumerates
  mounted Commands in order, including the one asking.
- `sessions()` returns the live Sessions owned by this terminal, so a command
  can start or resume a conversation without constructing parallel state.
- `useAgent()` puts another Agent in charge of answering from here on. The
  conversation under way moves over with it: the new Agent is handed the
  History the old one was answering, nothing changes on the screen, and it is
  the next answer that comes from elsewhere. A command that knows the two are
  not interchangeable installs a fresh History on the new Agent afterwards.
- `stop()` leaves the terminal.

`Commands::run()` reports a technical `CommandExecution` with `completed`,
`unknown` or `failed` status; Commands return `void`. The TUI presents failures
as visible errors and stays usable. The failure retains the original exception.

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
`NeuronInteraction\Command\CommandInterface`, and is carried out at any time.

```php
use NeuronTui\Conversation\ConcurrentControls;
use NeuronTui\Command\ConcurrentCommandInterface;
use NeuronInteraction\Command\CommandArguments;

final class Version implements ConcurrentCommandInterface
{
    public function name(): string
    {
        return 'version';
    }

    public function describe(): string
    {
        return 'Says which build is answering.';
    }

    public function run(ConcurrentControls $controls, CommandArguments $arguments): void
    {
        $controls->say('Build ' . MyApp::VERSION);
    }
}
```

Both interfaces carry the name, description and `run()` contract; what changes
is what `run()` is handed. In exchange for overlapping a Turn, such a command
receives the **ConcurrentControls**: only
`say()`, `warn()`, `commands()` and `stop()`. These controls expose neither
selection nor Agent access or prompting. The marker interface and this policy
are TUI-specific; Neuron Interaction imposes no concurrency policy on backend
Adapters.

`LeaveCommand` and `HelpCommand` are the two shipped commands that run this
way: leaving and reading what may be typed here change no conversation.

### The commands this library ships

Neuron Interaction supplies Session Commands; Neuron TUI supplies terminal
Help and Leave Commands. Each accepts a neutral name at construction, so a
Host Application that prefers `/quit` to `/exit` passes `quit`:

| Class | Terminal invocation | What it does |
| --- | --- | --- |
| `NeuronInteraction\Command\ClearCommand` | `/clear` | Starts a new Session, leaving the current one stored. |
| `NeuronInteraction\Command\ResumeCommand` | `/resume` | Lets you choose a stored Session to resume. |
| `NeuronTui\Command\LeaveCommand` | `/exit` | Closes the Conversation TUI. Concurrent. |
| `NeuronTui\Command\HelpCommand` | `/help` | Lists what can be typed here. Concurrent. |

```php
use NeuronInteraction\Command\ClearCommand;
use NeuronTui\Command\HelpCommand;
use NeuronTui\Command\LeaveCommand;
use NeuronInteraction\Command\ResumeCommand;

Tui::make($agent)->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new LeaveCommand('quit'),
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

A **Command kit** is a group of commands mounted in one line. `SessionCommandKit` is
provided by Neuron Interaction, grouping both Commands that touch Sessions.

```php
use NeuronTui\Command\LeaveCommand;
use NeuronInteraction\Command\SessionCommandKit;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage('/var/lib/my-app');

Tui::make($agent)
    ->setStorage($storage)
    ->addCommand([new SessionCommandKit(), new LeaveCommand()])
    ->run();
```

A kit can be taken with some of its commands left out, or with only the named
ones kept, so an application in which conversations are not thrown away has
`/resume` without `/clear`:

```php
use NeuronInteraction\Command\ClearCommand;

Tui::make($agent)->addCommand([
    (new SessionCommandKit())->exclude([ClearCommand::class]),
    new LeaveCommand(),
])->run();

// The other way round, keeping only what is named:
Tui::make($agent)
    ->addCommand((new SessionCommandKit())->only([ClearCommand::class]))
    ->run();
```

Both answer with a kit of their own and leave the one asked untouched, so the
same kit can be mounted twice, differently. A kit is unrolled when it is added:
afterwards a command that arrived in a kit and one mounted on its own are the
same thing, with the same listing and rules.

A Host Application groups commands of its own by extending
`NeuronInteraction\Command\AbstractCommandKit` and naming them in `provide()`; the
`NeuronInteraction\Command\CommandKitInterface` interface is what the Conversation TUI
mounts. Renaming a command stays the command's own business, so a kit is the
short way in and writing its commands out by hand remains the way to give them
names of one's own.

`SessionCommandKit` is the concrete shared kit; interfaces use the `Interface`
suffix, including `CommandInterface`, `CommandControlsInterface`,
`CommandKitInterface` and `StorageInterface`.

## Sessions

A Session is one conversation with the Agent. `ClearCommand` starts a fresh one
without leaving the terminal: the screen and the composer empty, and the
conversation that was on screen stays where it is stored.

`ResumeCommand` lists the Sessions in the configured Storage in the Picker, most recently
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
use NeuronInteraction\Command\SessionCommandKit;
use NeuronInteraction\Storage\FileStorage;

$storage = new FileStorage('/var/lib/my-app');

Tui::make($agent)
    ->setStorage($storage)
    ->addCommand(new SessionCommandKit())
    ->run();
```

`FileStorage` separates each interaction namespace beneath that root. The Host
Application neither implements Neuron AI's `ChatHistoryInterface` nor installs
a Session History on the Agent: the runtime starts its initial Session and
owns History composition. Neuron TUI never deletes a stored conversation.

## Input history

Submitted messages and Commands share one ordered Input history per configured
Storage, across Sessions and Adapters. Blank submissions are ignored and only
consecutive exact duplicates collapse. Generated Agent prompts are excluded.
The TUI owns its navigation cursor and draft: from an empty composer, ↑ recalls
older inputs, ↓ moves toward newer ones and restores the empty draft past the
newest input. Editing a recalled input leaves navigation. A Picker or Command
suggestions owns the arrow keys while its list is active.

The package's `NeuronInteraction\InputHistory\InputHistory` offers `record()`
and `entries()` for other Adapters. The same class offers optional `older()`,
`newer()`, `isNavigating()` and `leave()` methods, with a cursor and draft local
to each instance. The TUI binds these methods to its keyboard events; a web
frontend may instead navigate the sequence locally in JavaScript.
No legacy persistence reader, fallback or automatic migration is provided.
Old files are left untouched and are outside the extracted package's contract.

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

Neuron Interaction can be developed and verified independently in its own
repository with `composer test` and `composer stan`. Its
[backend example](https://github.com/asterixcapri/neuron-interaction/blob/feat/extract-neuron-interaction/examples/backend.php)
demonstrates shared modules and selection across separate requests.

The automated suite uses Neuron AI's fake provider and Symfony TUI's virtual
terminal. It requires no credentials and makes no network requests.

## License

Neuron TUI is released under the MIT License.
