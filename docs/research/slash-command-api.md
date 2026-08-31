# Slash command programmabili

Documento di design. Le decisioni qui dentro vengono dal grilling; la ricerca
in [`claude-code-interface-basics.md`](claude-code-interface-basics.md) entra
solo come vincolo e come confine di ciò che resta fuori (ultima sezione).

I termini sono quelli di [`CONTEXT.md`](../../CONTEXT.md), aggiornato insieme
a questo documento: **Slash command**, **Controls**, **Command kit**,
**Picker**, **Session**, **Session provider**.

## Il problema

`neuron-cli` è lo strato TUI che manca a Neuron AI. Il motore c'è già —
toolkit del filesystem, `ToolApproval`, `TodoPlanning`, MCP, Workflow — e non
va replicato. Quello che manca è un terminale in cui una Host Application
possa dire *cosa si può digitare*.

Oggi i comandi sono un enum chiuso di tre voci, con scritto nel sorgente che
un registro non è giustificato. Questo documento inverte quella decisione: è
il motivo per cui serve un ADR.

## Le decisioni

**Nessun comando è montato d'ufficio.** `new NeuronCli($agent)` chatta e si
esce con `Ctrl+C`. La libreria *fornisce* `Clear`, `Sessions`, `Leave` e
`Help`, ma non ne monta nessuno finché l'host non lo chiede.

**Il `SessionProvider` esce dal costruttore di `NeuronCli`.** Se le Session si
raggiungono solo dai comandi, il posto dove vivono le conversazioni si nomina
nel costruttore dei comandi che lo usano.

**Una conversazione non appartiene a un Agent.** Il provider è un posto pieno
di ChatHistory; nessuna di esse registra chi l'ha scritta. Cambiare Agent non
tocca le Session.

**`ask()` manda e finisce.** Non aspetta la risposta. L'attesa, se servirà,
arriverà con un verbo suo — non cambiando il significato di questo.

**I subagent non sono in questa versione.** Il design li regge: servirebbe un
verbo in più (`delegate()`) e nient'altro.

**Un comando che gira mentre l'Agent lavora non può aprire il Picker.** Non
per convenzione: per tipo. Riceve meno Controls.

**Un comando che va in errore non uccide la TUI.**

## Il seam

```php
namespace NeuronCli\Conversation;

/**
 * Chi risponde a un nome digitato dopo lo slash.
 */
interface Command
{
    /** Il nome per cui risponde, slash incluso: '/review'. */
    public function name(): string;

    /** Una riga sola, per /help. */
    public function describe(): string;
}

/**
 * Un comando che pretende che l'Agent stia fermo.
 *
 * È la regola: cambiare conversazione a turno in corso farebbe atterrare una
 * risposta dove non appartiene, quindi un comando che arriva mentre l'Agent
 * lavora viene rifiutato.
 */
interface SlashCommand extends Command
{
    public function run(Controls $controls, string $arguments): void;
}

/**
 * Un comando che si può eseguire anche mentre l'Agent lavora.
 *
 * Non riceve i Controls interi: da qui non si apre un Picker, perché una
 * persona non deve scegliere da un elenco mentre sotto scorrono le risposte,
 * e non si tocca l'Agent, perché sta rispondendo.
 */
interface RunsWhileWorking extends Command
{
    public function run(LimitedControls $controls, string $arguments): void;
}
```

## Controls

Otto verbi. Dietro restano i venticinque metodi pubblici di
`ConversationView`, che nessun comando vede.

```php
final class Controls
{
    /** Una riga nella conversazione. */
    public function say(string $text): void;

    /** Come say, ma segnala che qualcosa non è andato. */
    public function warn(string $text): void;

    /**
     * Manda un prompt all'Agent, come se l'avesse scritto la persona, e
     * finisce: la risposta arriva sullo schermo, non al comando.
     */
    public function ask(string $prompt): void;

    /**
     * Apre il Picker. Sospende finché la persona sceglie; torna la chiave
     * scelta, o null se ha premuto Escape.
     *
     * @param array<string, string> $options chiave => etichetta
     */
    public function choose(string $title, array $options): ?string;

    /**
     * L'Agent è dell'host: cambiargli provider, istruzioni, tool o History è
     * affare suo, e passa dall'API di Neuron AI invece che da verbi qui.
     */
    public function agent(): Agent;

    /** Un altro Agent risponde da qui in avanti, sulla stessa conversazione. */
    public function useAgent(Agent $agent): void;

    /** @return list<Command> i comandi montati, per /help */
    public function commands(): array;

    public function stop(): void;
}

final class LimitedControls
{
    public function say(string $text): void;

    public function warn(string $text): void;

    /** @return list<Command> */
    public function commands(): array;

    public function stop(): void;
}
```

Cambiare conversazione non ha un verbo: è `agent()->setChatHistory()`, e lo
schermo si riallinea da solo per la riconciliazione.

## I comandi nella scatola

Nessuno di questi è montato senza che l'host lo chieda.

```php
namespace NeuronCli\Command;

final class Leave implements RunsWhileWorking
{
    public function __construct(private readonly string $name = '/exit') {}

    public function name(): string { return $this->name; }

    public function describe(): string { return 'Leaves the terminal.'; }

    public function run(LimitedControls $controls, string $arguments): void
    {
        $controls->stop();
    }
}

final class Help implements RunsWhileWorking
{
    public function __construct(private readonly string $name = '/help') {}

    public function name(): string { return $this->name; }

    public function describe(): string { return 'Lists what can be typed here.'; }

    public function run(LimitedControls $controls, string $arguments): void
    {
        foreach ($controls->commands() as $command) {
            $controls->say($command->name() . ' — ' . $command->describe());
        }
    }
}

final class Clear implements SlashCommand
{
    public function __construct(
        private readonly SessionProvider $sessions,
        private readonly string $name = '/clear',
    ) {}

    public function name(): string { return $this->name; }

    public function describe(): string { return 'Starts a fresh conversation.'; }

    public function run(Controls $controls, string $arguments): void
    {
        $controls->agent()->setChatHistory($this->sessions->start());
    }
}

final class Sessions implements SlashCommand
{
    public function __construct(
        private readonly SessionProvider $sessions,
        private readonly string $name = '/sessions',
    ) {}

    public function name(): string { return $this->name; }

    public function describe(): string { return 'Returns to an earlier conversation.'; }

    public function run(Controls $controls, string $arguments): void
    {
        $sessions = $this->sessions->list();

        if ($sessions === []) {
            $controls->warn('There is no earlier Session to return to yet.');

            return;
        }

        $labels = [];

        foreach ($sessions as $session) {
            $labels[$session->key] = $session->title;
        }

        $chosen = $controls->choose('Sessions', $labels);

        if ($chosen !== null) {
            $controls->agent()->setChatHistory($this->sessions->resume($chosen));
        }
    }
}
```

## Command kit

Sul modello dei toolkit di Neuron AI: un gruppo di comandi che si montano
insieme e che si porta dietro ciò che serve loro.

```php
interface CommandKitInterface
{
    /** @return list<Command> */
    public function commands(): array;

    /** @param list<class-string> $classes */
    public function exclude(array $classes): static;

    /** @param list<class-string> $classes */
    public function only(array $classes): static;
}

final class SessionKit extends AbstractCommandKit
{
    public function __construct(private readonly SessionProvider $sessions) {}

    protected function provide(): array
    {
        return [new Clear($this->sessions), new Sessions($this->sessions)];
    }
}
```

## Il montaggio

```php
// Il minimo: si chatta, si esce con Ctrl+C.
(new NeuronCli($agent))->run();
```

```php
// Una TUI completa.
$sessions = new FileSessionProvider('/var/lib/app/sessions');

(new NeuronCli(
    agent: $agent,
    title: 'Coding Agent',
    commands: [
        new Leave(),
        new Help(),
        new SessionKit($sessions),
        new Model(['sonnet' => $sonnet, 'haiku' => $haiku]),
        new Review(),
    ],
))->run();
```

```php
// Nomi propri, e il kit senza /clear.
commands: [
    new Leave('/quit'),
    new SessionKit($sessions)->exclude([Clear::class]),
]
```

## Quello che scrive la Host Application

```php
final class Model implements SlashCommand
{
    /** @param array<string, AIProviderInterface> $models */
    public function __construct(private readonly array $models) {}

    public function name(): string { return '/model'; }

    public function describe(): string { return 'Switches the model.'; }

    public function run(Controls $controls, string $arguments): void
    {
        $names = array_combine(array_keys($this->models), array_keys($this->models));

        $chosen = $arguments !== '' ? $arguments : $controls->choose('Model', $names);

        if ($chosen === null) {
            return;
        }

        if (!isset($this->models[$chosen])) {
            $controls->warn('No model called ' . $chosen . '.');

            return;
        }

        $controls->agent()->setAiProvider($this->models[$chosen]);
        $controls->say('Now answering with ' . $chosen . '.');
    }
}

final class Review implements SlashCommand
{
    public function name(): string { return '/review'; }

    public function describe(): string { return 'Reviews what is staged in git.'; }

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
```

## Cosa cambia dentro

**`Submission::interpret()`** smette di conoscere i nomi e torna nome più
argomenti. Sparisce con lei il difetto per cui oggi `/exit now` diventa
`UnknownSlashCommand`.

```php
public static function interpret(string $input): CommandInvocation|MessageForAgent
{
    if (!str_starts_with($input, '/')) {
        return new MessageForAgent($input);
    }

    $name = (string) strtok($input, " \t\n");

    return new CommandInvocation($name, trim(substr($input, strlen($name))));
}
```

**`NeuronCli`** tiene un registro invece di un `match`, e l'Agent smette di
essere `readonly`.

```php
/** @var array<string, Command> */
private readonly array $commands;

private Agent $agent;
```

Due comandi che rivendicano lo stesso nome sono un errore al montaggio, non
un override silenzioso: `InvalidArgumentException`.

**`carryOut()`** diventa lookup, rifiuto, esecuzione protetta e
riconciliazione.

```php
private function carryOut(CommandInvocation $invocation): void
{
    $command = $this->commands[$invocation->name] ?? null;

    if ($command === null) {
        $this->view->showUnknownSlashCommand($invocation->name);

        return;
    }

    if ($this->turns->isBusy() && !$command instanceof RunsWhileWorking) {
        $this->view->showError(
            $invocation->name . ' is refused while the Agent is working. '
                . 'Try it again once the turn has finished.',
        );

        return;
    }

    async(function () use ($command, $invocation): void {
        try {
            $command instanceof RunsWhileWorking
                ? $command->run($this->limitedControls, $invocation->arguments)
                : $command->run($this->controls, $invocation->arguments);
        } catch (Throwable $exception) {
            // Il codice di un comando è dell'host: la TUI gli sopravvive.
            $this->view->showError($exception::class . ': ' . $exception->getMessage());
        }

        // Qualunque cosa il comando abbia fatto — History, Agent, provider —
        // lo schermo torna a dire quello che l'Agent ha adesso. L'invariante
        // è una riconciliazione, non un divieto.
        $this->view->showHistory($this->agent->getChatHistory()->getMessages());
    });
}
```

**`AgentTurn`** riceve l'Agent per turno invece di tenerlo:

```php
public function respond(Agent $agent, string $message): void
```

**`SessionPicker` diventa `Picker`**: prende un titolo e coppie
chiave/etichetta, non conosce più `Session`. La traduzione da Session a
etichetta scende nel comando `Sessions`. `SelectListWidget` di `symfony/tui`
è già generico, quindi non c'è niente da inventare.

`TurnQueue`, `HistoryProjection`, `Session` e i due Session provider non si
toccano.

## Vincoli noti

`symfony/tui` è **sperimentale**: banner nel README, `@experimental` su ogni
file, nessuna BC promise, e la documentazione ufficiale non è ancora
pubblicata. Il ramo 8.2 ha già un `[BC BREAK]` sul costruttore di
`SelectListWidget`, cioè il widget su cui poggia `choose()`. Fonti nella
ricerca.

Il componente non usa Fiber di suo: il Picker è a callback (`onSelect`,
`onCancel`). Ma `symfony/tui` e amphp girano entrambi su
`revolt/event-loop`, quindi far sospendere `choose()` con un `DeferredFuture`
risolto dalla callback è fattibile. È l'**unico** punto sospensivo del
design, ed è il pezzo tecnicamente più rischioso.

## Fuori scope

Questo lavoro rende programmabili i comandi. Non fa nessuna delle altre
funzionalità base che la ricerca elenca, e conviene dirlo perché "slash
command programmabili" può facilmente essere letto come se le includesse:

- `Esc` che interrompe un turno senza uscire dalla TUI;
- la riga di stato viva, con i token consumati;
- l'approvazione dei tool (`ToolApproval` di Neuron AI non è ancora agganciato);
- la cronologia dei prompt digitati;
- `/compact`, e il riassunto della conversazione;
- il testo integrale di una chiamata a tool, oggi troncato a 120 caratteri;
- i subagent.

L'unica voce della ricerca che questo lavoro copre è `/help` — che diventa
scrivibile, non montato d'ufficio — e per riflesso il difetto di
`Submission::interpret()` su `/exit now`.

## Da chiarire scrivendo il codice

1. Come far sospendere `choose()` su Revolt senza bloccare il loop.
2. Se `LimitedControls` debba avere anche `ask()`: accodare un turno mentre
   l'Agent lavora è ciò che già succede quando una persona scrive, quindi non
   sarebbe scorretto — ma nessun comando nella scatola ne ha bisogno.
