# Tool non bloccanti in Neuron AI 3.15.x

Ricerca del 31 luglio 2026. Fonti: codice e discussioni ufficiali Neuron AI, documentazione ufficiale Amp. Nel repository non era presente una convenzione per le note di ricerca, quindi questa nota inaugura `docs/research/`.

## Risposta breve

Neuron AI 3.15.x non offre un contratto asincrono per i tool: non esistono `executeAsync()`, un tipo di risultato futuro o chunk di avanzamento durante l'esecuzione. `ToolInterface::execute()` restituisce `void`, mentre il risultato leggibile è una `string` ([ToolInterface 3.15.26, righe 65-86](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Tools/ToolInterface.php#L65-L86)).

Un tool personalizzato può però essere **cooperativamente non bloccante**. La callback può usare API basate su Amp/Revolt — per esempio `Amp\delay()`, `Future::await()` o `Amp\Process` — e restituire infine il valore risolto. Le Fiber di Amp sospendono la coroutine corrente lasciando vivo il loop Revolt; di conseguenza timer e animazioni della TUI possono continuare ([Amp: coroutine e Future](https://amphp.org/amp), [Amp: installazione ed esempio `delay`](https://amphp.org/installation)).

Questa possibilità non rende automaticamente non bloccanti i tool esistenti. Una callback che usa `sleep()`, `stream_get_contents()`, `file_get_contents()` o altro I/O PHP sincrono blocca ancora l'intero processo. Amp chiarisce esplicitamente che le coroutine sono cooperative e che una funzione di I/O bloccante ferma tutto il processo ([Amp: motivazione e modello cooperativo](https://amphp.org/amp)).

Per la demo di neuron-cli, quindi, **non occorre modificare il core di Neuron** per mantenere animata la TUI durante Bash: occorre sostituire il `BashTool` integrato con un tool basato su `Amp\Process`. Non è invece possibile rendere genericamente cooperativo qualunque tool sincrono limitandosi ad avvolgerlo in `Amp\async()`.

## Versioni verificate

La dipendenza dichiarata dall'applicazione è `neuron-core/neuron-ai:^3.15.26`, ma il lockfile e `vendor/` contengono attualmente **3.15.30**, commit `14efa3479513c032b54f51613e23fe5f16b516a8`.

Sono stati confrontati i tag ufficiali `3.15.26` (`ae5be8f065b19c9b7a5adff596b1d04fc07daf67`) e `3.15.30`. Fra questi tag non cambiano `Tool`, `ToolInterface`, `ToolNode`, `ParallelToolNode`, `AgentHandler`, `WorkflowHandler` o `AsyncExecutor`; le conclusioni valgono quindi per entrambe le patch. Il confronto `3.15.0..3.15.30` mostra lo stesso contratto di esecuzione dei tool per tutta la serie 3.15.x.

## Contratto ed esecuzione effettiva

`Tool::execute()`:

1. valida gli input;
2. chiama direttamente la callback o `__invoke()`;
3. passa immediatamente il valore restituito a `setResult()`.

`setResult()` serializza gli array in JSON e forza qualunque altro valore a `string` ([Tool 3.15.26, righe 185-190 e 224-296](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Tools/Tool.php#L185-L190)). Il percorso callback è visibile nelle [righe 224-296](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Tools/Tool.php#L224-L296).

Ne derivano due regole:

- la callback può sospendersi internamente e attendere operazioni Amp, purché alla fine restituisca uno scalare o un array;
- restituire direttamente `Amp\Future`, una Promise Guzzle o un `Generator` non è supportato: Neuron non li riconosce né li attende e tenta di convertirli a stringa.

Le API Amp 3 hanno volutamente una forma sincrona grazie alle Fiber: `Future::await()` sospende la coroutine e `Amp\delay()` restituisce `void`. Non serve quindi propagare `Future` attraverso il contratto di Neuron ([documentazione ufficiale Amp](https://amphp.org/amp), [guida di migrazione alle Fiber](https://amphp.org/upgrade)).

## ToolNode, handler ed eventi

`WorkflowHandler::events()` è un `Generator` pull-based: consegna l'evento corrente e, quando il consumatore chiede il successivo, avanza il workflow con `Generator::next()` ([WorkflowHandler 3.15.26, righe 32-71](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Workflow/WorkflowHandler.php#L32-L71)). `AgentHandler::getMessage()` consuma tutto e dichiara esplicitamente di bloccare fino al completamento ([AgentHandler 3.15.26, righe 15-27](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Agent/AgentHandler.php#L15-L27)).

Per ogni tool, `ToolNode` produce questa sequenza:

```text
ToolCallChunk
executeSingleTool()
ToolResultChunk
```

Il chunk iniziale arriva prima dell'esecuzione, ma non esistono chunk intermedi mentre il tool lavora ([ToolNode 3.15.26, righe 70-78](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Agent/Nodes/ToolNode.php#L70-L78)). `executeSingleTool()` emette sincronicamente `ToolCalling`, invoca `$tool->execute()` e infine emette `ToolCalled` ([ToolNode 3.15.26, righe 87-108](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Agent/Nodes/ToolNode.php#L87-L108)).

Questo consente alla CLI di mostrare subito “Running…”. Durante l'esecuzione:

- con un tool PHP bloccante, né il generatore né il loop Revolt fanno progressi;
- con un tool cooperativo Amp, il generatore non emette comunque output parziale, ma il loop Revolt continua a eseguire timer e repaint, quindi spinner ed elapsed possono animarsi;
- per mostrare stdout incrementale servirebbe un nuovo evento di progresso o un'altra API esplicita: il contratto attuale consegna solo il risultato finale.

Anche l'`EventBus` di Neuron chiama gli observer direttamente nello stesso stack, senza scheduling asincrono ([EventBus 3.15.26, righe 56-86](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Observability/EventBus.php#L56-L86)).

## Perché il toolkit filesystem blocca

Il `FileSystem\BashTool` incluso in 3.15.26 e 3.15.30 usa `proc_open()`, poi legge stdout e stderr con `stream_get_contents()` e chiude con `proc_close()` ([BashTool 3.15.26, righe 49-113](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Tools/Toolkits/FileSystem/BashTool.php#L49-L113)). Queste chiamate non sospendono cooperativamente sul loop Revolt.

Gli altri tool del [FileSystemToolkit ufficiale](https://github.com/neuron-core/neuron-ai/tree/3.15.26/src/Tools/Toolkits/FileSystem) usano anch'essi le API filesystem sincrone di PHP. Per operazioni brevi il fermo può essere impercettibile; Bash rende evidente il problema quando il comando dura alcuni secondi.

`amphp/process` è invece un dispatcher asincrono di processi figli integrato con Revolt. `Process::start()`, gli stream Amp e `join()` permettono di attendere senza fermare il loop ([documentazione ufficiale `amphp/process`](https://amphp.org/process)).

## Cosa fanno — e non fanno — le opzioni “parallel” e “async”

### `parallelToolCalls(true)`

L'opzione sceglie `ParallelToolNode` al posto del normale `ToolNode` ([Agent 3.15.26, righe 42-87](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Agent/Agent.php#L42-L87)). Non rende cooperativo un singolo tool:

- senza `pcntl` o `spatie/fork`, torna al percorso sequenziale;
- con un solo tool, torna esplicitamente al percorso sequenziale;
- soltanto con più tool nella stessa `ToolCallMessage` li esegue in processi figli tramite `Spatie\Fork`;
- non produce `ToolResultChunk` finché `Fork::run()` non ha restituito gli stati serializzati.

Il comportamento è nel [ParallelToolNode 3.15.26, righe 40-100](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Agent/Nodes/ParallelToolNode.php#L40-L100). La documentazione ufficiale conferma che serve per più tool richiesti dal modello nella stessa risposta e richiede `pcntl` e `spatie/fork` ([Tools: Parallel Tool Calls](https://docs.neuron-ai.dev/agent/tools#parallel-tool-calls)). Il maintainer ha inoltre confermato che l'opzione inietta il `ParallelToolNode` dedicato ([issue ufficiale #528](https://github.com/neuron-core/neuron-ai/issues/528#issuecomment-4161264554)).

È quindi parallelismo fra più tool, basato su processi, non non-blocking I/O del singolo tool. Nell'installazione attuale di neuron-cli `spatie/fork` non è presente, quindi l'opzione farebbe comunque fallback sequenziale.

### `Workflow\Executor\AsyncExecutor`

`AsyncExecutor` usa `Amp\async()` e Future per eseguire contemporaneamente i rami creati da un `ParallelEvent` ([AsyncExecutor 3.15.26, righe 18-91](https://github.com/neuron-core/neuron-ai/blob/3.15.26/src/Workflow/Executor/AsyncExecutor.php#L18-L91)). Non modifica `ToolNode`, che non genera un `ParallelEvent`, e non rende asincrona una callback tool.

La spiegazione del maintainer descrive la stessa separazione: `AsyncExecutor` è un executor dei rami Workflow e richiede che un nodo restituisca esplicitamente `ParallelEvent` ([issue ufficiale #530](https://github.com/neuron-core/neuron-ai/issues/530#issuecomment-4288452066)).

### Vecchie API async dell'agente

L'issue ufficiale [#149](https://github.com/neuron-core/neuron-ai/issues/149) documenta `chatAsync()` e Promise nella vecchia serie 1.x, per eseguire più sessioni agente in parallelo. Quell'API non compare nel sorgente 3.15.x e, anche nel contesto storico, riguardava richieste agente distinte, non il protocollo di ritorno delle callback tool. Non costituisce quindi supporto a un tool che restituisce `Future` o Promise.

## Verifiche empiriche locali

Gli esperimenti sono stati eseguiti sul pacchetto installato 3.15.30. Il codice interessato è invariato rispetto a 3.15.26. Ogni prova ha registrato un timer `Revolt\EventLoop::repeat()` mentre chiamava `Tool::execute()`.

| Callback | Risultato | Tick Revolt durante circa 120 ms |
|---|---|---:|
| `usleep(120000)` e poi stringa | stringa corretta | 0 |
| `Amp\delay(0.12)` e poi stringa | stringa corretta | 11 |
| `Amp\Process` con `sleep 0.12`, poi stringa | stringa corretta | 16 |
| ritorno diretto di `Amp\Future` | errore “could not be converted to string” | n/a |
| ritorno diretto di Promise Guzzle | stesso errore | n/a |
| ritorno diretto di `Generator` | stesso errore | n/a |

Queste prove non sostituiscono il contratto sorgente, ma confermano la distinzione operativa: **attendere internamente con Amp funziona; restituire un oggetto asincrono a Neuron no**.

## Implicazioni per neuron-cli

La soluzione più piccola che cambia davvero l'esperienza è un tool Bash compatibile con lo schema del toolkit, ma implementato con `Amp\Process`:

1. avviare il processo con `Process::start()`;
2. consumare stdout e stderr Amp contemporaneamente, per evitare che una pipe piena fermi il processo;
3. attendere `join()` e gli stream all'interno di `__invoke()`;
4. restituire il normale array finale del `BashTool`.

Durante queste attese il loop Revolt rimane disponibile allo spinner della TUI. Neuron continua a ricevere un array già risolto e non richiede modifiche.

Se neuron-cli usa direttamente Amp deve dichiarare le dipendenze necessarie. Neuron 3.15.26 tiene `amphp/amp`, `amphp/http-client` e `spatie/fork` soltanto in `require-dev` ([composer.json 3.15.26, righe 12-36](https://github.com/neuron-core/neuron-ai/blob/3.15.26/composer.json#L12-L36)). Amp raccomanda di non affidarsi alle dipendenze transitive e di dichiarare quelle usate dall'applicazione ([Amp: dipendenze](https://amphp.org/installation#dependencies)). neuron-cli dichiara già `amphp/amp`; se adotta `Amp\Process` dovrebbe dichiarare direttamente anche `amphp/process`.

Limite finale: questa modifica rende cooperativo Bash, non l'intero ecosistema dei tool. Per tool terzi che fanno I/O bloccante servono una riscrittura con librerie non bloccanti oppure l'offload in un vero processo/thread. Avvolgere la stessa funzione bloccante in una Fiber non introduce preemption e non mantiene vivo il loop.
