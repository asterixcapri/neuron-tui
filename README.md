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

## Slash commands

The Conversation TUI carries out `/clear`, `/sessions` and `/exit` itself. A
Host Application mounts commands of its own beside them, so the terminal can
do what its application needs:

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
nothing was typed. Two commands answering to the same name — or one taking a
name the TUI already answers to — stop the construction of the Conversation
TUI rather than one of them silently winning.

While it runs, a command is handed the **Controls**, and nothing else: the
widgets of the Conversation TUI stay out of reach.

- `say()` writes a line in the conversation.
- `warn()` writes one that reports something did not go as it should.
- `ask()` puts a prompt to the Agent as though the person had written it, and
  finishes: the answer arrives on the screen, not back in the command.
- `agent()` returns the Agent, so its provider, instructions, tools and
  History change through the Neuron AI API rather than through verbs here.
- `stop()` leaves the terminal.

A command that fails leaves the exception as a line of error in the
conversation, exactly as a failing turn does, and the terminal stays usable.
Like `/clear` and `/sessions`, a mounted command is refused while the Agent is
working, and can be typed again once the turn has finished.

## Sessions

A Session is one conversation with the Agent. `/clear` starts a fresh one
without leaving the terminal: the screen and the composer empty, and the
conversation that was on screen stays where it is stored.

`/sessions` lists the Sessions of this Agent, most recently used first, each
labelled with the first thing the person wrote in it and when it was last
used. While the list is open the composer takes no text: the arrow keys move
through it, typing narrows it, Enter resumes the chosen Session, and Escape
leaves the current one alone. Resuming paints that conversation and the Agent
answers with its context. A Session nobody wrote in is not listed.

Sessions come from a **Session provider**. Without configuration they are kept
in memory and last as long as the process, and Neuron CLI writes nothing
anywhere. Keeping them on disk, or anywhere else, is one argument:

```php
use NeuronCli\Session\FileSessionProvider;

(new NeuronCli(
    agent: $agent,
    sessionProvider: new FileSessionProvider('/var/lib/my-app/sessions'),
))->run();
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
[ADR 0001](docs/adr/0001-sessions-replace-the-agent-chat-history.md).

`NeuronCli\NeuronCli` is the public module, and two seams are the dependencies
an application may supply. The Session provider:
`NeuronCli\Session\SessionProvider` to implement,
`NeuronCli\Session\Session` to list a Session with, and
`NeuronCli\Session\InMemorySessionProvider` and
`NeuronCli\Session\FileSessionProvider` as the two shipped providers. And the
Slash commands it mounts: `NeuronCli\Conversation\SlashCommand` to implement,
`NeuronCli\Conversation\Command` behind it, and
`NeuronCli\Conversation\Controls` as what a command is handed while it runs.
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
variables take precedence over values from `examples/.env`. Use `/exit` or
Ctrl+C to close it.

## Controls

- Enter sends a message.
- Shift+Enter inserts a line break.
- Escape clears the unsent draft.
- PageUp and PageDown browse the History.
- `/clear` starts a new Session.
- `/sessions` lists the Sessions and resumes the one you choose.
- `/exit` or Ctrl+C closes the Conversation TUI.
- Any command the Host Application mounted, by the name it answers to.

`/clear` and `/sessions` are refused while the Agent is working, so an
arriving answer cannot land in the wrong Session; `/exit` works at any time.
Unknown Slash commands stay in the composer so they can be corrected and are
never sent to the Agent.

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
