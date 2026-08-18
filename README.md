# Neuron CLI

Neuron CLI is a reusable Conversation TUI for
[Neuron AI](https://github.com/neuron-core/neuron-ai). A Host Application
supplies a configured Agent; this library owns only the interactive terminal
experience.

## Installation

```bash
composer require asterixcapri/neuron-cli
```

Neuron CLI requires PHP 8.4.1 or newer and an interactive TTY.

## Usage

Configure the Agent in your application, then pass it to `NeuronCli`:

```php
use NeuronAI\Agent\Agent;
use NeuronCli\NeuronCli;

$agent = new Agent();
$agent->setAiProvider($provider);

(new NeuronCli($agent))->run();
```

The default header uses generic Neuron AI branding. A title and subtitle can
be supplied when the terminal should identify a particular Agent or product:

```php
(new NeuronCli(
    agent: $agent,
    title: 'Research Agent',
    subtitle: 'Ask about the knowledge base',
))->run();
```

A terminal built this way chats and nothing else: no Slash command is mounted
unless the Host Application names it, so every name typed after a slash is
unknown, and `Ctrl+C` is the way out.

## Slash commands

The Conversation TUI mounts nothing on its own. A Host Application mounts the
commands it wants — its own, and the ones this library ships — so the terminal
does what its application needs:

```php
use NeuronCli\Conversation\Controls;
use NeuronCli\Conversation\SlashCommand;

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

(new NeuronCli(agent: $agent, commands: [new Review()]))->run();
```

A command answers to a name, slash included, and describes itself in one
line. Whatever is typed after the name reaches it as its arguments, empty when
nothing was typed. No name is reserved, and two commands answering to the same
name stop the construction of the Conversation TUI rather than one of them
silently winning.

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
- `choose()` offers a list — a title and key and label pairs — and waits there
  for the key of the line the person chose, or nothing at all if they
  cancelled. It is the one verb that waits, and the terminal goes on painting
  while the list is open.
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
conversation, exactly as a failing turn does, and the terminal stays usable. A
command is refused while the Agent is working, and can be typed again once the
turn has finished. `Leave`, the command this library ships to close the
terminal, is the one exception and works at any time.

### The commands this library ships

Four commands come with Neuron CLI, and they are mounted like any other. Each
takes the name it answers to at construction, so a Host Application that
prefers `/quit` to `/exit` writes no command of its own:

| Class | Default name | What it does |
| --- | --- | --- |
| `NeuronCli\Conversation\Commands\Clear` | `/clear` | Starts a new Session, leaving the current one stored. |
| `NeuronCli\Conversation\Commands\Sessions` | `/sessions` | Lists the stored Sessions and resumes the one chosen. |
| `NeuronCli\Conversation\Commands\Leave` | `/exit` | Closes the Conversation TUI. |
| `NeuronCli\Conversation\Commands\Help` | `/help` | Lists what can be typed here. |

```php
use NeuronCli\Conversation\Commands\Clear;
use NeuronCli\Conversation\Commands\Help;
use NeuronCli\Conversation\Commands\Leave;
use NeuronCli\Conversation\Commands\Sessions;
use NeuronCli\Session\InMemorySessionProvider;

$sessions = new InMemorySessionProvider();

(new NeuronCli(agent: $agent, commands: [
    new Clear($sessions),
    new Sessions($sessions),
    new Leave('/quit'),
    new Help(),
]))->run();
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

## Sessions

A Session is one conversation with the Agent. `Clear` starts a fresh one
without leaving the terminal: the screen and the composer empty, and the
conversation that was on screen stays where it is stored.

`Sessions` lists the Sessions of this Agent in the Picker, most recently used
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
use NeuronCli\Session\FileSessionProvider;

$sessions = new FileSessionProvider('/var/lib/my-app/sessions');

(new NeuronCli(agent: $agent, commands: [
    new Clear($sessions),
    new Sessions($sessions),
]))->run();
```

An application that keeps conversations in its own storage implements
`NeuronCli\Session\SessionProvider` instead. It answers three questions:
create a Session, list the Sessions, and open one by its key. Opening returns
the Neuron AI chat history that Neuron CLI installs on the Agent; saving,
reloading and deserializing remain Neuron AI's work. Neuron CLI never deletes
a stored conversation.

Starting a Session replaces the History configured on the Agent by the Host
Application, because a provider builds every History it hands back. An
application that keeps its conversations somewhere passes the Session provider
reaching that place. See
[ADR 0001](docs/adr/0001-sessions-replace-the-agent-chat-history.md), and
[ADR 0002](docs/adr/0002-the-conversation-tui-mounts-nothing-on-its-own.md)
for why the provider is named on the commands instead of on the terminal.

`NeuronCli\NeuronCli` is the public module, and two seams are the dependencies
an application may supply. The Session provider:
`NeuronCli\Session\SessionProvider` to implement,
`NeuronCli\Session\Session` to list a Session with, and
`NeuronCli\Session\InMemorySessionProvider` and
`NeuronCli\Session\FileSessionProvider` as the two shipped providers. And the
Slash commands it mounts: `NeuronCli\Conversation\SlashCommand` to implement,
`NeuronCli\Conversation\Command` behind it,
`NeuronCli\Conversation\Controls` as what a command is handed while it runs,
and `NeuronCli\Conversation\Commands\Clear`,
`NeuronCli\Conversation\Commands\Sessions`,
`NeuronCli\Conversation\Commands\Leave` and
`NeuronCli\Conversation\Commands\Help` as the commands shipped ready to
mount.
Every other class under the `NeuronCli` namespace is annotated `@internal`, carries
no stability promise, and may be renamed, split, or removed in any release. Static analysis enforces this on the examples, which
are the reference Host Application.

The Host Application remains responsible for constructing the Agent,
providers, credentials, tools, History persistence, and the script or
framework command that launches the interaction. Neuron CLI does not provide
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

- Enter sends a message.
- Shift+Enter inserts a line break.
- Escape clears the unsent draft.
- PageUp and PageDown browse the History.
- Ctrl+C closes the Conversation TUI, mounted commands or not.
- Any command the Host Application mounted, by the name it answers to —
  including `Clear`, `Sessions`, `Leave` and `Help` when it mounted them.

A command is refused while the Agent is working, so an arriving answer cannot
land in the wrong Session; `Leave` is the exception and works at any time. Unknown Slash commands
stay in the composer so they can be corrected and are never sent to the Agent.

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

Neuron CLI is released under the MIT License.
