# L'interfaccia di `Agent` come riferimento per `NeuronTui\Tui`

## Decisione successiva alla ricerca

Il grilling architetturale successivo a questa ricerca ha conservato gli idiomi
utili di `NeuronAI\Agent\Agent`, ma ha respinto il suo modello duale per la TUI
([ADR 0003](../adr/0003-the-tui-is-composed-around-a-required-agent.md)).
La futura `NeuronTui\Tui`:

- è `final` e compone un Agent obbligatorio;
- offre `Tui::make($agent)` come forma documentata e mantiene equivalente il
  costruttore pubblico;
- espone configurazione runtime fluente con `setTitle()`, `setSubtitle()` e
  `addCommand()`, ma non `setAgent()` o hook protetti;
- conserva soltanto configurazione e dipendenze fino a `run()`, dove costruisce
  widget e listener;
- congela la configurazione all'avvio e può essere eseguita una volta sola;
- usa `run(): void`, perché governa una sessione terminale e non una singola
  inferenza.

Il caso comune resta quindi:

```php
use NeuronTui\Tui;

Tui::make($agent)->run();
```

La forma configurata segue il vocabolario di Neuron:

```php
Tui::make($agent)
    ->setTitle('Research Agent')
    ->setSubtitle('Ask about the knowledge base')
    ->addCommand([
        new SessionKit($sessions),
        new Help(),
        new Leave(),
    ])
    ->run();
```

Una Host Application che vuole riusare una composizione la tiene in una propria
factory, senza specializzare la TUI:

```php
final class ResearchTerminal
{
    public static function make(Agent $agent, SessionProvider $sessions): Tui
    {
        return Tui::make($agent)
            ->setTitle('Research Agent')
            ->addCommand([
                new SessionKit($sessions),
                new Help(),
                new Leave(),
            ]);
    }
}

ResearchTerminal::make(ResearchAgent::make(), $sessions)->run();
```

La factory riceve un Agent già configurato: `Tui` non configura provider,
istruzioni, tool, middleware o History al suo posto.
Lo stesso contratto copre un sistema multi-Agent: la TUI riceve l'Agent
coordinatore che espone il sistema come conversazione, oppure permette a un
comando della Host Application di sostituire l'Agent attivo. Non deve conoscere
il grafo, il routing o la delega interni al sistema.

## Perimetro e versione osservata

Il package dichiara `neuron-core/neuron-ai:^3.15.26`, ma `composer.lock`
installa **3.15.30**, commit `14efa3479513c032b54f51613e23fe5f16b516a8`
([vincolo locale](../../composer.json), [lockfile locale](../../composer.lock),
[tag ufficiale 3.15.30](https://github.com/neuron-core/neuron-ai/tree/3.15.30)).
L'analisi dell'API è quindi fissata a 3.15.30. Le pagine del sito ufficiale
sono usate per la filosofia dichiarata dal progetto; per firme e tipi fa fede
il sorgente del tag, perché la documentazione online evolve senza essere
versionata insieme all'installazione.

## Che cosa rende riconoscibile l'API di `Agent`

### `make()` è una costruzione alternativa, non una factory di dominio

`Agent` eredita `StaticConstructor` da `Workflow`. Il trait implementa
`make(...$arguments): static` facendo soltanto `new static(...$arguments)`:
preserva il late static binding e inoltra gli argomenti al costruttore
([`StaticConstructor`, righe 7-16](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/StaticConstructor.php#L7-L16),
[`Workflow`, righe 38-45](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/Workflow.php#L38-L45)).

Il costruttore di `Workflow` contiene soltanto infrastruttura di esecuzione:
Persistence, resume token e stato; inizializza inoltre executor e middleware
([`Workflow`, righe 65-89](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/Workflow.php#L65-L89)).
Provider, istruzioni e tool non sono parametri del costruttore di `Agent`.

Conseguenza per `Tui`: `make()` è appropriato e rende l'uso familiare, ma non
serve una factory separata né un `TuiConfig`. Poiché `Tui` è final, `make()` può
essere un semplice alias tipizzato del costruttore, non un service locator né
un punto di estensione basato sul late static binding.

### La configurazione è duale: hook protetti e override runtime

L'Agent consente due modi complementari di esprimere la stessa configurazione:

| Concetto | Default di sottoclasse | Override runtime | Risoluzione |
|---|---|---|---|
| Provider | `protected provider()` | `setAiProvider()` | `resolveProvider()` |
| Istruzioni | `protected instructions()` | `setInstructions()` | `resolveInstructions()` |
| Tool | `protected tools()` | `addTool()` | `getTools()` / bootstrap |
| History | `protected chatHistory()` | `setChatHistory()` | `getChatHistory()` |
| Middleware | `protected middleware()` / `globalMiddleware()` | `addMiddleware()` / `addGlobalMiddleware()` | bootstrap del Workflow |

Il provider runtime viene memorizzato e restituito fluentemente; se manca,
`resolveProvider()` memoizza il valore dell'hook protetto
([`ResolveProvider`, righe 14-34](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/ResolveProvider.php#L14-L34)).
Le istruzioni seguono lo stesso schema
([`HandleInstructions`, righe 9-27](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleInstructions.php#L9-L27)).

I concetti cumulativi usano invece `add`: `getTools()` unisce i tool aggiunti
con quelli dell'hook, e `addTool()` accetta un elemento o un array, aggiunge e
invalida la cache di bootstrap
([`HandleTools`, righe 90-106](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleTools.php#L90-L106),
[`HandleTools`, righe 177-198](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleTools.php#L177-L198)).
History è sostitutiva, dunque usa `setChatHistory()` e ritorna la stessa
istanza
([`HandleAgentState`, righe 19-37](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleAgentState.php#L19-L37)).
Middleware ripete la stessa distinzione: hook protetti per i default,
`addGlobalMiddleware()` e `addMiddleware()` per l'accumulo runtime
([`HandleMiddleware`, righe 29-97](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/HandleMiddleware.php#L29-L97)).

È intenzionale che Neuron supporti entrambe le forme. La documentazione
ufficiale [raccomanda la sottoclasse](https://docs.neuron-ai.dev/agent/agent)
per incapsulare e rendere portatile un Agent, ma presenta la definizione
fluente come alternativa inline. Questa dualità è adatta all'Agent, i cui hook
ne definiscono capacità e comportamento, ma non va copiata automaticamente
nella TUI. La configurazione del terminale appartiene alla Host Application e
può essere riusata tramite una sua factory, mantenendo `Tui` final e composta.

### I nomi descrivono la semantica della modifica

L'API non segue un unico prefisso artificiale:

- `setAiProvider`, `setInstructions`, `setChatHistory`, `setPersistence` e
  `setStartEvent` sostituiscono un valore;
- `addTool`, `addNode`, `addMiddleware` e `observe` accumulano comportamento;
- `parallelToolCalls`, `toolMaxRuns` e `toolErrorHandler` sono fluenti ma
  portano il nome del concetto, non `with...`.

Tutti mutano l'istanza e ritornano `$this`, sebbene i tipi dichiarati non siano
perfettamente uniformi (`self`, `Agent`, `AgentInterface`, `WorkflowInterface`
o `static` a seconda del metodo). Gli esempi sono visibili nell'interfaccia
pubblica di [`AgentInterface`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/AgentInterface.php#L14-L49),
nei [setter di `Workflow`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/Workflow.php#L91-L175)
e in [`Agent::parallelToolCalls()`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/Agent.php#L52-L62).

Per `Tui` conviene conservare i verbi. Poiché la classe è final, i metodi
fluenti possono dichiarare coerentemente `self`: non esiste una sottoclasse da
preservare nello static analyser. `withTitle()` suggerirebbe invece un value
object immutabile, che non è il modello osservato in Neuron.

### Configurare e poi eseguire: la mutabilità ha un confine temporale

`chat()` e `stream()` non restituiscono subito una risposta: configurano il
modo di esecuzione e restituiscono un `AgentHandler`
([`Agent`, righe 103-141](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/Agent.php#L103-L141)).
Sul handler:

- `getMessage(): Message` esegue bloccando fino al completamento
  ([`AgentHandler`, righe 13-27](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/AgentHandler.php#L13-L27));
- `events(): Generator` espone gli eventi raw oppure li traduce con uno
  `StreamAdapterInterface`;
- `run(): WorkflowState` consuma gli eventi e restituisce lo stato finale
  ([`WorkflowHandler`, righe 24-87](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/WorkflowHandler.php#L24-L87)).

La configurazione è quindi mutabile, ma pensata per precedere l'esecuzione.
Inoltre `Agent::compose()` non ricompone i nodi dopo il bootstrap
([`Agent`, righe 64-89](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/Agent.php#L64-L89)).
Cambiare modalità o parti strutturali dopo l'avvio della stessa istanza non è
un buon modello da imitare.

Per `Tui` questo implica due regole concrete:

1. setter e `addCommand()` restano validi finché `run()` non è iniziato;
2. view, command registry, callback e proiezione della History vengono creati
   lazy in `run()`, dopo la risoluzione definitiva della configurazione.

Una modifica dopo l'avvio deve fallire esplicitamente con una eccezione di
configurazione, non aggiornare solo metà dell'albero di widget.

## `Agent`, non `AgentInterface`, nell'API corrente

Idealmente una TUI dipenderebbe dall'interfaccia più stretta. In 3.15.30,
però, `AgentInterface` espone `chat()`, `stream()`, setter di provider,
istruzioni, tool e History, ma **non** `getChatHistory()`
([`AgentInterface`, righe 14-49](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/AgentInterface.php#L14-L49)).
L'implementazione concreta espone invece sia setter sia getter
([`HandleAgentState`, righe 24-37](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleAgentState.php#L24-L37)).

La TUI risultante legge la History iniziale quando costruisce il runtime
([`ConversationRuntime`](../../src/Conversation/ConversationRuntime.php)) e può
sostituire l'Agent preservandone la History nello stesso modulo. Non può quindi
tipizzarsi correttamente contro l'`AgentInterface` installata. Al momento della
ricerca queste responsabilità vivevano ancora nell'entry point precedente,
allora chiamato `NeuronCli`; quel nome descrive lo stato storico, non l'API
pubblica attuale.

La decisione per questa major è richiedere `NeuronAI\Agent\Agent` nel
costruttore e in `make()`. Non esistono né `setAgent()` né un hook `agent()`:
l'Agent iniziale è una dipendenza obbligatoria, mentre un comando montato può
ancora sostituire quello attivo tramite `Controls::useAgent()`. Non va inventata
un'interfaccia locale che l'Agent di Neuron non implementa nominalmente, né va
eliminato l'accesso alla History solo per poter dichiarare il tipo più
astratto. Questo punto può essere riesaminato se Neuron amplia
`AgentInterface` o introduce un contratto di conversazione sufficiente.

## Il requisito «Agent o sistema di Agent»

Neuron usa `Workflow` proprio per orchestrare sistemi multi-Agent: un nodo può
contenere un Agent e un Workflow può modellare flussi, rami, streaming e
interruzioni arbitrari
([Workflow: Getting Started](https://docs.neuron-ai.dev/workflow/getting-started),
[upgrade v3](https://docs.neuron-ai.dev/overview/upgrade)). Questo non rende
però ogni `Workflow` una conversazione. Il contratto base espone
`init()`, `run()` e un `WorkflowState`; non promette `chat()`, `stream()` con
messaggi, né `getChatHistory()`
([`Workflow`, righe 129-195](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/Workflow.php#L129-L195)).

Per questa major la seam reale è quindi un **top-level/coordinator `Agent`**:
la Host Application incapsula dietro quell'Agent il routing verso altri Agent
o l'avvio del Workflow multi-Agent, e consegna il coordinatore a `Tui`. In
alternativa, i comandi della Host possono cambiare l'Agent attivo usando la
capacità che la TUI possiede già. Accettare direttamente un `Workflow`
arbitrario imporrebbe invece alla TUI di inventare input, output e History che
il tipo non garantisce.

Solo quando esisterà un secondo esecutore conversazionale concreto conviene
estrarre un port dedicato con le operazioni effettivamente richieste (eventi
streaming, History e interruzioni). Anticiparlo oggi produrrebbe un adapter
nominale che `Agent` non implementa e non renderebbe più semplice l'uso del
framework.

## Esecuzione TUI e futura interfaccia web

La TUI attuale sceglie correttamente il percorso streaming: per ogni Turn
chiama `Agent::stream(new UserMessage(...))->events()` e interpreta
`TextChunk`, `ToolCallChunk` e `ToolResultChunk`
([`AgentTurn`, righe 45-85](../../src/Conversation/AgentTurn.php)). Questo è il
livello di integrazione giusto per un renderer terminale: gli eventi raw
conservano la semantica necessaria per aggiornare testo e attività dei tool.

`StreamAdapterInterface` appartiene invece al confine di protocollo. Il
`WorkflowHandler` può passare gli eventi attraverso un adapter prima di
restituirli, e la documentazione ufficiale descrive adapter per protocolli web
come Vercel AI SDK e AG-UI
([sorgente del punto di estensione](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/WorkflowHandler.php#L24-L71),
[upgrade ufficiale v3](https://docs.neuron-ai.dev/overview/upgrade#streaming-adapters)).

Quindi una futura UI web non dovrebbe derivare da `Tui`, e `Tui` non dovrebbe
diventare un `StreamAdapterInterface`. Le due interfacce possono condividere
un coordinatore di Turn quando emergerà un secondo caso concreto, mentre:

- `Tui` consuma eventi raw e gestisce terminale, focus, keybinding e repaint;
- l'adapter web traduce gli stessi eventi nel protocollo scelto;
- l'Agent continua a possedere provider, tool, middleware, History e
  interruzioni in entrambi i casi.

## Confronto con il costruttore attuale

La baseline osservata durante la ricerca era un entry point `NeuronCli` final
che riceveva nel costruttore Agent, title, subtitle, Terminal e commands;
montava subito i comandi, costruiva la view, leggeva la History e collegava i
callback. L'implementazione successiva ha assunto la nuova identità pubblica
[`Tui`](../../src/Tui.php) e ha spostato l'assemblaggio live in
[`ConversationRuntime`](../../src/Conversation/ConversationRuntime.php).

| Aspetto | Baseline storica (`NeuronCli`) | Idioma di `Agent` | Direzione proposta |
|---|---|---|---|
| Costruzione | `new NeuronCli(...)` | `Agent::make()` | `Tui::make(...)` e `new` entrambi validi |
| Configurazione | parametri constructor-only | hook protetti + fluent runtime | Agent nel costruttore, opzioni fluenti |
| Estensione | classe `final` | sottoclasse raccomandata | conservare `Tui` final |
| Valori singoli | named arguments | `set...` | `setTitle`, `setSubtitle` |
| Collezioni | array `commands` | `addTool`, `addMiddleware` | `addCommand`, anche con array/kit |
| Risoluzione | eager nel costruttore | prevalentemente lazy | lazy fino a `run()` |
| Esecuzione | `run(): void` | handler + eventi/stato | conservare `run(): void` |
| Dipendenza AI | `Agent` concreto | `AgentInterface` incompleto per History | conservare `Agent` in 3.x |

La correzione principale rispetto alla proposta constructor-only non è
estetica. Senza costruzione lazy, `setTitle()` o `addCommand()` dopo `make()`
arriverebbero quando view e registry sono già stati materializzati. L'API
fluente richiede quindi di spostare il bootstrap della UI dal costruttore a
`run()`.

## Tre interfacce candidate

### A. Solo costruttore dichiarativo

```php
(new Tui(
    agent: $agent,
    title: 'Research Agent',
    commands: [new Help(), new Leave()],
))->run();
```

**Vantaggi.** È semplice, tipizzata e vicina all'implementazione esistente;
ogni istanza nasce completa.

**Svantaggi.** Non segue l'entry point `::make()` mostrato dalla documentazione
Neuron, rende rumoroso il caso di configurazione incrementale e spinge ogni
futura opzione nel costruttore.

**Esito:** respinta come API obiettivo. Non è prevista compatibilità con la
vecchia interfaccia.

### B. Builder fluente soltanto

```php
Tui::make($agent)
    ->setTitle('Research Agent')
    ->addCommand([new Help(), new Leave()])
    ->run();
```

**Vantaggi.** È leggibile, usa correttamente `set`/`add`, evita un costruttore
in crescita, rende obbligatorio l'Agent nel sistema di tipi e somiglia agli
esempi fluenti di Neuron. Una factory della Host Application può incapsulare
una composizione riusabile senza creare punti di estensione nella TUI.

**Costo.** Richiede di accumulare la configurazione fino a `run()` e di
impedire modifiche successive all'avvio.

**Esito:** scelta raccomandata.

### C. Configurazione duale, come `Agent`

Forma minima:

```php
Tui::make($agent)->run();
```

Forma runtime:

```php
Tui::make($agent)
    ->setTitle('Research Agent')
    ->addCommand($commands)
    ->run();
```

Forma incapsulata proposta durante la ricerca:

```php
final class ResearchTui extends Tui
{
    protected function agent(): Agent
    {
        return ResearchAgent::make();
    }

    protected function title(): string
    {
        return 'Research Agent';
    }

    protected function commands(): array
    {
        return [new Help(), new Leave()];
    }
}

ResearchTui::make()->run();
```

**Vantaggio.** Riproduce integralmente la grammatica mentale di Agent e
consente di distribuire una sottoclasse già configurata.

**Svantaggi.** Introduce due modi concorrenti per comporre lo stesso Adapter,
precedenze tra hook e mutatori, e un contratto di ereditarietà che non
corrisponde a una responsabilità propria della TUI. Provider, istruzioni e
tool definiscono un Agent specializzato; titolo e comandi terminali sono
invece scelte della Host Application.

**Esito:** respinta dopo il grilling architetturale. Si conserva il lessico
fluente di Agent, non il suo modello di sottoclasse.

## Firma pubblica raccomandata

La forma precisa, poi registrata nell'ADR 0003, è:

```php
namespace NeuronTui;

use NeuronAI\Agent\Agent;
use NeuronTui\Command\Command;
use NeuronTui\Command\CommandKit;
use Symfony\Component\Tui\Terminal\TerminalInterface;

final class Tui
{
    public function __construct(
        Agent $agent,
        ?TerminalInterface $terminal = null,
    );

    public static function make(
        Agent $agent,
        ?TerminalInterface $terminal = null,
    ): self;

    public function setTitle(string $title): self;

    public function setSubtitle(string $subtitle): self;

    /**
     * @param Command|CommandKit|list<Command|CommandKit> $commands
     */
    public function addCommand(
        Command|CommandKit|array $commands,
    ): self;

    public function run(): void;
}
```

Dettagli contrattuali:

- l'Agent è sempre presente e resta configurato dalla Host Application;
- titolo e sottotitolo hanno default `Neuron AI` e `Agent conversation`; i
  setter conservano anche eventuali stringhe vuote senza regole speciali;
- nessun comando è montato automaticamente;
- `addCommand()` accetta un `Command`, un `CommandKit` o un array, valida ogni
  elemento mentre viene aggiunto e conserva l'ordine, come `Agent::addTool()`;
- i nomi duplicati sono ammessi: la risoluzione usa il primo comando e i
  suggerimenti possono mostrare le ripetizioni, senza logica aggiuntiva;
- `TerminalInterface` resta un seam infrastrutturale opzionale, soprattutto
  per test; non merita un hook di dominio né un `setTerminal()` nell'API
  introduttiva;
- ogni mutatore verifica con un solo stato di avvio che la TUI non sia già
  partita; la stessa istanza può eseguire `run()` una volta sola;
- il bootstrap di view, registry e listener avviene lazy dentro `run()`;
- non vengono aggiunti metodi per provider, model, tool, middleware,
  persistence o History: chi li configura continua a farlo sull'Agent.

Non è necessario riusare direttamente `NeuronAI\StaticConstructor`: replicare
le quattro righe di `make()` evita di accoppiare l'API pubblica della TUI a un
trait di utilità del framework, conservando la convenzione osservabile con una
firma completamente tipizzata.

## Cosa non copiare da `Agent`

Seguire l'interfaccia di Agent non significa trasformare la TUI in un Agent:

- `Tui` **non estende `Agent`**: non è un soggetto che inferisce;
- `Tui` **non estende `Workflow`**: il suo `run()` governa molti Turn, input e
  repaint, mentre un Workflow produce eventi e uno stato finale;
- `Tui` non espone `setAiProvider()`, `addTool()` o `addMiddleware()` in
  delega: creerebbe due proprietari apparenti della stessa configurazione;
- `Tui` non sceglie `chat()` contro `stream()` come opzione utente: un
  terminale interattivo ha bisogno degli eventi streaming e delle attività
  tool;
- `Tui` non restituisce `AgentHandler` o `WorkflowState` da `run()`: la sua
  unità di esecuzione è la sessione terminale, non il Turn.

La relazione corretta è dunque **composizione**: `Tui` ospita un Agent già
valido — anche l'Agent coordinatore di un sistema di Agent — e ne guida più
esecuzioni streaming. L'interfaccia sembra appartenere all'ecosistema Neuron
perché ne adotta costruzione, lessico e pattern di configurazione, non perché
ne eredita il motore.

## Fonti primarie

- Neuron AI 3.15.30:
  [`Agent`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/Agent.php),
  [`AgentInterface`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/AgentInterface.php),
  [`Workflow`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/Workflow.php),
  [`StaticConstructor`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/StaticConstructor.php),
  [`AgentHandler`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/AgentHandler.php),
  [`WorkflowHandler`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/WorkflowHandler.php),
  [`ResolveProvider`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/ResolveProvider.php),
  [`HandleInstructions`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleInstructions.php),
  [`HandleTools`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleTools.php),
  [`HandleAgentState`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/HandleAgentState.php),
  [`HandleMiddleware`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Workflow/HandleMiddleware.php).
- Documentazione ufficiale Neuron AI:
  [Agent](https://docs.neuron-ai.dev/agent/agent),
  [Tools & Toolkits](https://docs.neuron-ai.dev/agent/tools),
  [Chat History](https://docs.neuron-ai.dev/agent/chat-history-and-memory),
  [Middleware](https://docs.neuron-ai.dev/workflow/middleware),
  [upgrade v3](https://docs.neuron-ai.dev/overview/upgrade).
- Stato locale risultante:
  [`composer.json`](../../composer.json),
  [`composer.lock`](../../composer.lock),
  [`Tui`](../../src/Tui.php),
  [`ConversationRuntime`](../../src/Conversation/ConversationRuntime.php),
  [`AgentTurn`](../../src/Conversation/AgentTurn.php).
