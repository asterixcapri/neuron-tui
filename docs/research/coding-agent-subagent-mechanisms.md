# Meccanismi dei subagent nei coding agent

Ricerca del 4 settembre 2026. Fonti esclusivamente primarie: documentazione
ufficiale e sorgenti dei repository ufficiali. I sorgenti sono citati a commit
fisso quando possibile.

## Domanda

Come avviano e coordinano i subagent Codex, Claude Code, Pi e OpenCode? In
particolare: il chiamante resta bloccato, come prosegue la comunicazione dopo
l'avvio, come arrivano progressi, domande e risultato, e dove passa il confine
fra tool, protocollo, runtime e UI?

## Risposta breve

Non esiste un normale tool asincrono capace di "restituire due volte". I sistemi
che permettono alla conversazione principale di proseguire trasformano il
subagent in una risorsa identificata e posseduta dal runtime:

```text
tool/comando ──► runtime ──► subagent identificato
                    │
                    ├──► eventi di stato ──► UI
                    └──► messaggi tipizzati ──► turno dell'Agent
```

La separazione delle due direzioni è inevitabile a livello di esecuzione; non è
però necessario esporla come due concetti di prodotto. OpenCode è l'esempio più
vicino alla forma cercata per Neuron TUI: un solo `task` tool avvia un child,
lo continua passando lo stesso `task_id`, oppure lo lancia in background; il
runtime conserva il job e, alla fine, inietta il risultato sintetico nella
Session padre. La funzione è ancora sperimentale nel sorgente osservato
([Task tool, righe 24-102](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/opencode/src/tool/task.ts#L24-L102)).

Codex e Claude Code espongono invece più azioni al modello (`spawn`, `send`,
`wait`/`stop`), ma sotto di esse hanno comunque un'unica identità di thread o
agent e un runtime di comunicazione. Pi sceglie deliberatamente di non avere
subagent nel core; il suo esempio ufficiale è un tool sincrono che avvia processi
figli, mostra progressi e restituisce una sola volta alla fine.

## Quadro comparativo

| Prodotto | Unità persistente durante la delega | Avvio | Il padre continua? | Comunicazione successiva | Consegna del risultato |
|---|---|---|---|---|---|
| Codex | thread figlio registrato nell'albero della Session | `spawn_agent` | sì, lo spawn restituisce l'identità senza attendere la fine | `send_message` accoda; `followup_task` avvia un nuovo turno; sono disponibili anche wait/interruzione | evento di completamento nella mailbox del padre, senza svegliarlo automaticamente nel percorso V2 osservato |
| Claude Code | subagent con agent ID e transcript separato | `Agent` | foreground no; background sì (default corrente) | `SendMessage` indirizzato per ID/nome; si può anche aprire il transcript e scrivere lì | completion notification in un turno successivo |
| Pi | nessuna unità nel core; nell'esempio, processo `pi --mode json --no-session` | tool di estensione `subagent` | no: il tool attende il processo | nessuna nel riferimento ufficiale; stdin è chiuso e la Session del figlio non è salvata | normale risultato finale del tool |
| OpenCode | child Session + BackgroundJob con lo stesso ID | `task` | foreground no; background sì | lo stesso `task`, con `task_id`, aggiunge lavoro alla stessa child Session | prompt sintetico automatico nella Session padre |

“Continua” significa che il modello principale può concludere il proprio turno e
la persona può avviare altri turni mentre il child lavora. Non significa che due
turni scrivano contemporaneamente nella stessa History.

## OpenAI Codex

### Avvio e scheduling

La documentazione pubblica di Codex non descrive ancora il protocollo multi-agent
del client. I fatti di questa sezione provengono quindi dal repository ufficiale
`openai/codex`, commit `8e6a44b`.

`spawn_agent` crea un nuovo thread, gli assegna un percorso stabile nell'albero
degli agenti, collega `parent_thread_id` e il turno radice, gli invia il primo
messaggio e restituisce al chiamante il riferimento del child. Non aspetta che il
child completi il compito
([spawn V2, righe 156-229](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/tools/handlers/multi_agents_v2/spawn.rs#L156-L229)).

Ogni Session dell'albero condivide lo stesso `AgentControl`: questo oggetto
possiede registro, limite di esecuzione e budget dell'albero, ed è distinto dal
tool che lo invoca
([AgentControl, righe 118-171](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/agent/control.rs#L118-L171)).
Il limite di concorrenza V2 è applicato alle esecuzioni dei subagent tramite una
guardia condivisa; lo slot viene rilasciato al `Drop` della guardia
([execution limiter, righe 13-97](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/agent/control/execution.rs#L13-L97)).

### Comunicazione dopo l'avvio

Codex V2 espone azioni diverse, ma `send_message` e `followup_task` attraversano
lo stesso percorso interno e producono entrambi una
`InterAgentCommunication`. La differenza è una proprietà semantica:
`send_message` accoda senza svegliare il destinatario, mentre `followup_task`
imposta `trigger_turn` e lo riattiva
([message tool, righe 1-24 e 52-128](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/tools/handlers/multi_agents_v2/message_tool.rs#L1-L128)).

Il runtime ha una mailbox tipizzata dentro la coda degli input: messaggi della
persona, risultati di funzioni e comunicazioni fra agenti sono varianti dello
stesso `TurnInput`; la mailbox è ordinata e viene drenata all'inizio di un turno
([input queue, righe 20-186](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/session/input_queue.rs#L20-L186)).
Solo una comunicazione con `trigger_turn` fa chiedere allo scheduler di avviare
un turno quando la Session è inattiva
([handler, righe 79-95](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/session/handlers.rs#L79-L95)).

### Risultati, progresso e UI

Alla conclusione di ogni turno V2, la Session figlia intercetta `TurnComplete` o
`TurnAborted`, emette l'attività `Completed` e inoltra al padre una
`InterAgentCommunication::Result` con `trigger_turn: false`: il risultato è
quindi reso disponibile al padre, ma non produce da solo un turno spontaneo
([inoltro del completamento, righe 2137-2283](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/core/src/session/mod.rs#L2137-L2283)).
Questo corregge un'assunzione facile ma sbagliata: almeno nel commit studiato,
“push al padre” non equivale a “nuova risposta automatica del padre”.

La UI non ricava lo stato interrogando il tool. Consuma thread item ed eventi di
attività separati, conserva l'ordine di spawn per navigare fra thread e offre
`/subagents` con anteprime bounded degli eventi recenti
([status feed, righe 1-57](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/tui/src/app/agent_status_feed.rs#L1-L57),
[navigation state, righe 1-51](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/tui/src/app/agent_navigation.rs#L1-L51)).
Gli eventi visibili distinguono almeno `Started`, `Interacted`, `Interrupted` e
`Completed`
([rendering, righe 159-182](https://github.com/openai/codex/blob/8e6a44b428e31f91b21edc97904fcdf4f0931ade/codex-rs/tui/src/app/agent_status_feed.rs#L159-L182)).

### Lifecycle e persistenza

I child sono veri thread Codex, non semplici callback: il controllo può
ricaricarli, inviare input, interromperli e osservare lo stato. Il sorgente
mostra anche la persistenza degli archi padre-child e percorsi di resume, ma la
documentazione di prodotto non stabilisce ancora una promessa pubblica completa
sulla sopravvivenza dei subagent a chiusura, crash e aggiornamenti. Va quindi
trattata come capacità implementativa, non come contratto stabile.

## Claude Code

### Subagent ordinari

Claude Code avvia un subagent con l'`Agent` tool. Ogni subagent ha contesto,
prompt, strumenti e permessi propri. I subagent possono essere foreground o
background: i primi bloccano la conversazione principale; i secondi lavorano
mentre la conversazione continua. Dalla versione 2.1.198 il background è il
default, salvo quando Claude necessita subito del risultato
([documentazione ufficiale, “Run subagents in foreground or background”](https://code.claude.com/docs/en/sub-agents#run-subagents-in-foreground-or-background)).

Il risultato di un background subagent arriva a Claude come completion
notification in un turno successivo. La UI tiene il task in `/tasks`, mostra lo
stato e permette di trasformare un task già avviato in background con `Ctrl+B`
([stessa sezione](https://code.claude.com/docs/en/sub-agents#run-subagents-in-foreground-or-background)).
Le richieste di permesso del child sono presentate nella Session principale e
identificano il subagent che le ha generate; questa è comunicazione runtime/UI,
non un dialogo mediato dalla risposta finale del tool.

Dopo lo spawn, Claude può usare `SendMessage` con l'agent ID o il nome. Su un
subagent attivo il messaggio è una correzione di rotta; su un subagent completato
lo riavvia in background mantenendo la History precedente. La persona può anche
aprire il transcript del child nel pannello e scrivergli direttamente
([“Resume subagents”](https://code.claude.com/docs/en/sub-agents#resume-subagents),
[“Observe and steer running forks”](https://code.claude.com/docs/en/sub-agents#observe-and-steer-running-forks)).

I transcript sono file separati `agent-{agentId}.jsonl`, sopravvivono alla
compaction del padre e possono essere ripresi dopo il riavvio della stessa
Session; sono rimossi secondo la retention configurata, 30 giorni per default
([“Resume subagents”](https://code.claude.com/docs/en/sub-agents#resume-subagents)).
Il limite documentato è 20 subagent contemporanei per Session, configurabile, e
il nesting predefinito arriva a tre livelli sotto la conversazione principale
([“Concurrent subagent limit”](https://code.claude.com/docs/en/sub-agents#concurrent-subagent-limit),
[“Let subagents spawn their own subagents”](https://code.claude.com/docs/en/sub-agents#let-subagents-spawn-their-own-subagents)).

L'implementazione interna di Claude Code non è pubblicata nel repository
ufficiale; non è quindi verificabile da fonte primaria se il subagent ordinario
sia una Fiber, un processo o un servizio remoto. La documentazione promette il
comportamento, non il meccanismo.

### Agent teams: un prodotto diverso ma istruttivo

Claude distingue esplicitamente i subagent dagli agent team. Un team ha lead,
teammate, task list condivisa e mailbox; ogni mailbox è un file JSON validato e
la consegna è considerata riuscita solo dopo la scrittura del file
([architettura ufficiale degli agent team](https://code.claude.com/docs/en/agent-teams#architecture)).
Questo modello serve alla collaborazione continua fra peer. Per una delega
self-contained, la stessa documentazione consiglia invece i subagent e il
ritorno di una sintesi: è un segnale utile a non trasformare il primo MVP di
Neuron TUI in un sistema di team distribuito.

Claude offre inoltre un supervisor per vere background Session: ogni Session è
un processo sotto il supervisor, può sopravvivere alla chiusura del terminale e
viene riattivata dal disco. Questa garanzia riguarda le background Session di
agent view; non va automaticamente attribuita a ogni subagent ordinario
([“The supervisor process”](https://code.claude.com/docs/en/agent-view#the-supervisor-process)).

## Pi coding agent

### Identità del progetto

Il progetto first-party corrente è **Pi Agent Harness**, pacchetto
`@earendil-works/pi-coding-agent`, repository canonico
[`earendil-works/pi`](https://github.com/earendil-works/pi); il manifest del
pacchetto indica lo stesso repository
([nome del pacchetto, righe 1-12](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/package.json#L1-L12),
[repository nel manifest, righe 103-108](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/package.json#L103-L108)).
Il vecchio URL [`badlogic/pi-mono`](https://github.com/badlogic/pi-mono) viene
reindirizzato da GitHub al repository corrente: non è un progetto concorrente
diverso. Il commit studiato è `2d41163`.

### Nessun meccanismo built-in

Pi dichiara intenzionalmente di non includere subagent nel core: propone tmux,
estensioni o package, lasciando all'Host la scelta del modello di orchestrazione
([README, righe 495-509](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/README.md#L495-L509)).

Il repository contiene però un esempio ufficiale completo. L'estensione registra
un unico tool `subagent`, con modalità singola, parallela e chain
([tool, righe 459-483](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/examples/extensions/subagent/index.ts#L459-L483)).
Ogni invocazione avvia un processo `pi` in JSON mode, con `--no-session` e stdin
ignorato; stdout JSONL alimenta aggiornamenti parziali del tool, poi il processo
chiude e il tool restituisce il testo finale
([esecuzione, righe 300-425](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/examples/extensions/subagent/index.ts#L300-L425)).

Quindi il riferimento ufficiale Pi:

- isola il child in un processo;
- mantiene animata e informata la UI tramite `onUpdate`;
- blocca il turno del parent fino alla fine del tool;
- non possiede un canale parent-child dopo l'avvio (`stdin: "ignore"`);
- non conserva la conversazione del child (`--no-session`).

La modalità parallela è un batch dentro lo stesso tool: massimo otto task,
quattro processi concorrenti, con aggiornamenti aggregati e un solo risultato
finale verso il modello
([documentazione dell'esempio, righe 91-117](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/examples/extensions/subagent/README.md#L91-L117),
[implementazione, righe 604-685](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/examples/extensions/subagent/index.ts#L604-L685)).
L'abort invia prima `SIGTERM` e tenta `SIGKILL` dopo cinque secondi
([righe 410-424](https://github.com/earendil-works/pi/blob/2d41163332c1a6d11c45911a92100fd2a55e4d1a/packages/coding-agent/examples/extensions/subagent/index.ts#L410-L424)).

Pi dimostra bene il reporting incrementale dentro un tool, ma non risolve il
requisito di continuare la chat e comunicare col child. È esattamente il limite
del primo prototipo proposto per Neuron TUI.

## OpenCode

### Child Session e un solo tool pubblico

OpenCode distingue agent primari e subagent; un primary invoca questi ultimi con
il Task tool, e ogni invocazione crea una child Session navigabile dalla Session
padre
([documentazione ufficiale degli agenti](https://opencode.ai/docs/agents#subagents),
[navigazione fra Session](https://opencode.ai/docs/agents#usage)).

Nel commit `70f7411`, `task` accetta `task_id`: se assente crea una child Session
con `parentID`; se presente riprende la Session esistente. Lo stesso parametro è
quindi l'identità attraverso cui il parent continua la conversazione del child
([Task tool, righe 43-172](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/opencode/src/tool/task.ts#L43-L172)).

Non risulta un processo figlio: `runTask` richiama il normale prompt runtime sulla
child Session. L'isolamento è di contesto e History, non di memoria o processo
([righe 197-225](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/opencode/src/tool/task.ts#L197-L225)).

### Background e consegna automatica

Il foreground attende `BackgroundJob.wait()` e restituisce il normale tool
result. Con `background: true`, il tool registra il lavoro e restituisce subito
uno stato `running`
([righe 284-344](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/opencode/src/tool/task.ts#L284-L344)).
Se lo stesso `task_id` è ancora attivo, un'altra chiamata `task` usa `extend` per
accodare il nuovo prompt alla medesima child Session
([righe 256-289](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/core/src/background-job.ts#L256-L289)).

Al completamento, un watcher aspetta il job e chiama il prompt runtime del padre
con un part testuale `synthetic` contenente task ID, stato e risultato. Questo
innesca un nuovo turno dell'Agent principale: non è un secondo ritorno del tool
originario
([Task tool, righe 227-264](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/opencode/src/tool/task.ts#L227-L264)).

`BackgroundJob` è una registry concorrente process-local basata su Effect,
Deferred e scope. Il commento del modulo è esplicito: stato e lavori non sono
durable; il restart del processo o la chiusura dello scope li perde o li
interrompe
([BackgroundJob, righe 113-124](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/core/src/background-job.ts#L113-L124)).
La cancellazione chiude lo scope e risolve lo stato come `cancelled`
([righe 337-360](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/core/src/background-job.ts#L337-L360)).

### Progressi, domande e UI

La UI ricostruisce i subagent dai child session ID e dai tool part, non dal
ritorno finale. Mantiene tab con `running/completed/error/cancelled`, raccoglie
gli eventi recenti del child e aggrega separatamente richieste di permesso e
domande
([subagent data, righe 295-706](https://github.com/anomalyco/opencode/blob/70f74112e3f4a33ea1af8209c979a5060d7d2a36/packages/opencode/src/cli/cmd/run/subagent-data.ts#L295-L706)).

Questo significa che il risultato per il modello e l'osservabilità per la
persona percorrono proiezioni diverse dello stesso child ID. Non sono due
subsystem concorrenti: sono due consumatori dello stesso lifecycle. Il sorgente
non mostra invece un protocollo diretto con messaggi tipizzati child→parent
paragonabile a Codex `InterAgentCommunication`; domande e permessi sono gestiti
dalla UI, mentre il contenuto per il parent arriva come prompt sintetico.

## Fatti, inferenze e lacune

### Fatti verificati

- Codex e OpenCode modellano il child come Session/thread autonomo con identità
  stabile, non come oggetto risultato di un tool.
- Claude Code conserva agent ID e transcript separato e permette follow-up.
- Codex separa mailbox e UI events sotto un controllo di Session condiviso.
- OpenCode riusa un solo tool con `task_id` sia per avvio sia per continuazione.
- Pi non impone un'architettura; il suo esempio ufficiale resta sincrono.
- Progressi visivi e input per il modello sono flussi distinti in tutti i design
  che li documentano chiaramente.

### Inferenze dichiarate

- L'identità stabile del child, non il canale o il processo, è l'astrazione che
  rende pulita la comunicazione dopo l'avvio. È un'inferenza comparativa dai
  quattro design.
- Il modello OpenCode è il punto di partenza più vicino al requisito espresso
  per Neuron TUI: una sola superficie tool, un runtime di job e una child History.
- `amphp/parallel` dovrebbe rimanere un adapter di esecuzione sotto questa
  astrazione. Far coincidere `Delegation` con `Execution` renderebbe impossibile
  sostituire in futuro il processo con un worker remoto o in-process.

### Non documentato o non verificabile

- Claude Code non pubblica l'implementazione dei subagent ordinari, quindi non è
  noto il confine esatto fra processo, task e servizio.
- La documentazione pubblica Codex non promette ancora il lifecycle completo dei
  subagent; i dettagli riportati sono contratti del sorgente osservato.
- OpenCode marca il background dei subagent come sperimentale nel codice; non va
  interpretato come API stabilizzata.
- Pi non offre un comportamento canonico oltre al semplice esempio incluso nel
  repository.

## Proposta risultante per Neuron TUI

Il confronto suggerisce di non creare né un tool che resta sospeso, né due
concetti pubblici “tool di avvio” e “mailbox”. La forma più piccola e coerente è:

```text
SubagentTool                         DelegationRuntime
  start(task) ────────────────────► create Delegation(id)
  continue(id, message) ──────────► send to the same Delegation
  cancel(id) ─────────────────────► cancel the same Delegation
                                           │
                                  amphp/parallel adapter
                                           │
                         ┌─────────────────┴─────────────────┐
                         ▼                                   ▼
                  Activity events                     Conversation input
                  (solo UI)                            (question/result)
```

### 1. Una sola superficie per il modello

Un solo tool, con un'identità stabile:

```text
subagent(task, agent?)                     -> avvia, restituisce id
subagent(id, message)                      -> continua o risponde
subagent(id, cancel: true)                 -> annulla
```

Sono azioni diverse dello stesso aggregate `Delegation`, come `task_id` in
OpenCode. Non occorre far scegliere al modello fra un tool “start” e un concetto
“mailbox”.

### 2. Il runtime, non il tool, possiede il lifecycle

`DelegationRuntime` conserva definizione autoloadabile, processo Amp, channel,
Cancellation, stato e relazione con la Session padre. Il tool termina subito
dopo aver consegnato il comando al runtime. `amphp/parallel` è un dettaglio
dell'adapter, non il dominio.

```text
created -> running <-> waiting
                    -> completed | failed | cancelled
```

### 3. Un solo protocollo tipizzato per la Delegation

Sul collegamento Amp viaggiano messaggi con `delegationId` e `messageId`:

```text
parent -> child: Start | Message | Answer | Cancel
child -> parent: Activity | Question | Completed | Failed
```

“Un solo protocollo” non significa “un solo consumer”. `Activity` aggiorna una
proiezione transitoria della TUI. `Question`, `Completed` e `Failed` diventano
input tipizzati della conversazione principale.

### 4. Regola di scheduling della Session padre

La History principale deve avere un solo writer. Se arriva un evento mentre il
padre sta rispondendo, va accodato. Quando è idle:

- `Question` avvia un turno automatico, perché il child è bloccato;
- `Completed` e `Failed` avviano un turno automatico che permette al padre di
  presentare o usare il risultato;
- `Activity` non sveglia mai il modello.

Questa scelta è più proattiva di Codex V2 (`Completed` queue-only) e coincide con
il comportamento osservato in OpenCode. Deve essere una policy esplicita del
runtime, non un side effect del renderer.

### 5. History separate ma correlate

Ogni Delegation ha una History effimera o persistita indipendentemente dalla
History padre. Per l'MVP può essere process-local, ma l'ID e il protocollo non
devono assumere che evapori: Claude Code e OpenCode mostrano che resume,
ispezione e continuazione diventano presto requisiti reali. Nella History padre
entrano soltanto:

- la chiamata che ha avviato o modificato la Delegation;
- domande significative;
- risultato o fallimento finale.

Gli aggiornamenti di attività rimangono una proiezione UI e possono sparire al
resume senza fingere di essere parte della conversazione.

### 6. Confini dei moduli

```text
ConversationRuntime
  serializza i turni e accetta input Person | Delegation

DelegationRuntime
  possiede lifecycle, routing, channel e Cancellation

SubagentTool
  traduce le decisioni del modello in comandi alla Delegation

DelegationActivityStore
  proiezione bounded per rendering, non fonte di verità

ParallelExecutionAdapter
  implementa il trasporto con amphp/parallel
```

Il punto architetturale decisivo è questo: **tool, protocollo e UI non sono tre
modi di gestire il subagent; sono tre porte sullo stesso aggregate
`Delegation`**. L'identità, lo stato e l'ordine dei messaggi appartengono al
runtime centrale.
