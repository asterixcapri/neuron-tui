# Clonare Claude Code come *interfaccia*: quali funzionalità basiche valgono per Neuron TUI

> Nota storica: questa ricerca descrive la revisione indicata sotto. Le sezioni
> su `ConcurrentCommandInterface`, `ConcurrentControls` e `Tui::addCommand()`
> precedono la revisione della composizione e non sono istruzioni per le API
> correnti. Help e Leave ora appartengono a Neuron Interaction e usano
> `CommandControlsInterface`; il montaggio avviene tramite `Commands`.
> Per gli esempi aggiornati, vedere il [README](../../README.md).

Ricerca del 18 agosto 2026. Fonti primarie: documentazione ufficiale Claude Code
(Anthropic), documentazione e sorgente ufficiali di Neuron AI, sorgente e blog
ufficiali di Symfony. La domanda è deliberatamente ristretta alla **superficie di
interazione** — Conversation TUI e riga di comando — non al modello.

Aggiornata il 3 settembre 2026 contro la revisione `978e649`, dopo il rename del
package e l'implementazione dei comandi programmabili, dei Command suggestions e
del nuovo Picker. Le osservazioni sulle dipendenze restano fissate alle versioni
indicate nelle rispettive sezioni.

> Nota sulle URL: le pagine `https://docs.claude.com/en/docs/claude-code/...`
> rispondevano il 18 agosto 2026 con un `301` verso
> `https://code.claude.com/docs/en/...` (verificato su `interactive-mode`). In
> questa nota si citano le URL finali osservate allora.

## Risposta breve

Neuron TUI ha già, senza saperlo, buona parte della *tastiera* di Claude Code:
l'`EditorWidget` di `symfony/tui` porta gratis quasi tutte le scorciatoie di
editing readline che Claude Code documenta. Quello che manca non è la tastiera,
sono **quattro comportamenti di conversazione**:

1. **interrompere un Turn** in corso senza uccidere il processo;
2. **approvare o rifiutare l'esecuzione di un tool** prima che parta;
3. **riusare quello che si è già digitato** (cronologia dei prompt);
4. **vedere lo stato della conversazione** (modello, contesto consumato, costo)
   in una riga di stato che esiste già ma descrive soltanto i tasti attivi.

Sono quattro cose piccole rispetto a ciò che Claude Code fa, e sono esattamente
quelle senza cui una Conversation TUI non sembra Claude Code. Tutto il resto —
hook, MCP, permessi su file, memoria `CLAUDE.md`, output style, checkpoint — o
appartiene alla Host Application, o appartiene al livello Agent di Neuron, e non
è materia di un milestone "basic".

Ordine consigliato aggiornato: **P0** interruzione del Turn · riga di stato viva.
**P1** approvazione dei tool · cronologia dei prompt · pensiero del modello
visibile · `/compact`. **P2** ridisegno `Ctrl+L` · `/export`. `/help`, Command
suggestions, Command estendibili e apertura di una History scelta dalla
Host Application sono nel frattempo presenti. **Fuori scope**: tutto il resto.

---

## 1. Cosa Neuron TUI ha già

Verificato originariamente leggendo `src/` alla revisione `978e649`; i
riferimenti ai simboli sono mantenuti aggiornati.

| Comportamento di Claude Code | Stato in Neuron TUI |
|---|---|
| `Enter` invia, `Shift+Enter` va a capo ([interactive-mode, "Multiline input"](https://code.claude.com/docs/en/interactive-mode)) | Presente: `ComposerEditor` eredita `submit`=`Enter`, `new_line`=`shift+enter` da `EditorWidget` |
| Editing readline: `Ctrl+A/E/K/U/W/Y`, `Alt+B/F`, `Alt+Y`, undo ([interactive-mode, "Text editing"](https://code.claude.com/docs/en/interactive-mode)) | Presente **gratis**: mappa di default di `EditorWidget` (`cursor_line_start`=`ctrl+a`, `delete_to_line_end`=`ctrl+k`, `yank`=`ctrl+y`, `yank_pop`=`alt+y`, `undo`=`ctrl+-`…) |
| Incolla in modalità paste ([interactive-mode](https://code.claude.com/docs/en/interactive-mode)) | Presente: bracketed paste abilitato da `Symfony\Component\Tui\Terminal\Terminal` |
| `Esc` svuota la bozza non inviata ([interactive-mode, `Esc`+`Esc`](https://code.claude.com/docs/en/interactive-mode)) | Presente, ma con un solo `Esc` (`ConversationView::clearDraft`) |
| Messaggi accodati mentre l'Agent lavora: «If you send a command while Claude is responding, it queues and runs after the current turn finishes» ([commands](https://code.claude.com/docs/en/commands)) | I prompt sono accodati da `TurnQueue`; un `ConcurrentCommandInterface` gira subito, mentre un normale `CommandInterface` viene rifiutato durante il Turn |
| Streaming del testo, attività dei tool con durata | Presente: `AgentTurn::respond()` su `TextChunk` / `ToolCallChunk` / `ToolResultChunk`, reso da `ToolActivity` e `ToolActivityText` |
| Indicatore di lavoro con secondi trascorsi | Presente: `WorkingIndicator` |
| `/clear` apre una conversazione nuova conservando la precedente ([sessions, "Manage context within a session"](https://code.claude.com/docs/en/sessions)) | Fornito da `Command\ClearCommand`; è presente quando la Host Application lo monta |
| `/resume` con picker: elenco, ricerca digitando, `↑↓`, `Enter`, `Esc` ([sessions, "Use the session picker"](https://code.claude.com/docs/en/sessions)) | Fornito da `Command\ResumeCommand` + `Tui\Picker`, ordinato dal più recente e corredato da titolo, età e — quando disponibile — dimensione persistita |
| `/help` e scoperta dei comandi ([commands](https://code.claude.com/docs/en/commands)) | Fornito da `Command\HelpCommand`; i Command suggestions compaiono e si filtrano mentre si scrive un nome, con `↑↓`, `Tab`, `Enter` ed `Esc` |
| `/exit` ([commands](https://code.claude.com/docs/en/commands)) | Fornito da `Command\LeaveCommand`; è presente quando la Host Application lo monta |
| Markdown ed evidenziazione della sintassi nelle risposte | Presente: `MarkdownWidget` + `tempest/highlight` |

Il confronto è più lusinghiero di quanto sembri: sul piano dell'*editing*
Neuron TUI è già quasi allineato, perché quella parte è del componente TUI.
I comandi forniti dal package non sono montati automaticamente: questa è una
decisione intenzionale della Conversation TUI, registrata nell'ADR 0002.

## 2. Il vocabolario, e come ci si mappa

| Claude Code | Neuron TUI |
|---|---|
| session | **Session** |
| `--resume` / `/resume` picker | comando **ResumeCommand** + **Picker** |
| transcript in `~/.claude/projects/...` | **History**, posseduta dall'Agent, raggiunta da un **Session provider** |
| turn | **Turn** |
| command | **Command** |
| status line | la riga `.status` della Conversation TUI |
| spinner / "esc to interrupt" | **Working indicator** |
| `settings.json`, `CLAUDE.md` | **Host Application** (Neuron TUI non legge configurazione) |

Nessun termine nuovo è necessario per le raccomandazioni che seguono, tranne uno
(§3, P1.1): una **Richiesta di approvazione** — e anche quello ha già un nome a
monte, `ApprovalRequest`, in Neuron AI.

---

## 3. Le raccomandazioni, in ordine di priorità

### P0.1 — Interrompere il Turn con `Esc`

**Cosa fa Claude Code.** `Esc` interrompe Claude o chiude una finestra: «Stop the
current response or tool call mid-turn so you can redirect. Claude keeps the work
done so far.» `Ctrl+C` invece «Interrupts a running operation. If nothing is
running, the first press clears the prompt input and a second press exits Claude
Code» ([interactive-mode, "General controls"](https://code.claude.com/docs/en/interactive-mode)).

**Perché conta.** È la differenza fra una TUI e un `echo | agent`. In Neuron TUI
l'unica risposta a un Turn che va per le lunghe è ancora `Ctrl+C`, che chiude
tutto: `ConversationRuntime::handleInput()` lega `quit` a `Key::ctrl('c')` e
chiama `view->stop()`. Un Turn sbagliato costa l'intera sessione di terminale.

**Come si mappa.** `Escape` è oggi consumato dal composer (`select_cancel` →
`clearDraft`). Il ramo naturale è: se `TurnQueue::isBusy()`, `Escape` interrompe
il Turn; altrimenti svuota la bozza — che è precisamente la disambiguazione di
Claude Code. L'interruzione vive in `AgentTurn`: il generatore restituito da
`AgentHandler::events()` è **pull-based** (`WorkflowHandler::events()` avanza il
workflow solo quando il consumatore chiede l'evento successivo, come già
documentato in [`neuron-non-blocking-tools.md`](neuron-non-blocking-tools.md)),
quindi smettere di iterare è sufficiente per fermare l'inferenza al prossimo
chunk. Il testo già ricevuto resta nella History — «Claude keeps the work done so
far» si ottiene gratis. Va deciso, e scritto, cosa entra nella History
dell'Agent per una risposta interrotta.

**Costo.** Piccolo: un ramo in `ConversationRuntime::handleInput()`, un flag
letto dal ciclo `foreach` di `AgentTurn::respond()`, e la coda dei messaggi da
svuotare o no.

### Realizzato — `/help` e Command suggestions

**Cosa fa Claude Code.** «Type `/` in Claude Code to see all available commands,
or type `/` followed by any letters to filter»; `/help` «Show help and available
commands»; e `?` su input vuoto apre un pannello con le scorciatoie
([interactive-mode, "Commands" e "Quick commands"](https://code.claude.com/docs/en/interactive-mode),
[commands](https://code.claude.com/docs/en/commands)). Nota di forma utile:
«Commands are only recognized at the start of your message» e «Text following the
command name becomes its arguments» ([commands](https://code.claude.com/docs/en/commands)) —
regole che `Submission::interpret()` implementa: produce uno
`CommandInput` con nome e argomenti separati.

**Stato in Neuron TUI.** La Host Application può montare `Command\HelpCommand`, che
elenca tutti i comandi montati attraverso `ConcurrentControls::commands()` e può
girare anche durante un Turn. `CommandSuggestions` mostra i nomi mentre si scrive
all'inizio del composer, li filtra e permette di scegliere, completare o eseguire
con `↑↓`, `Tab` ed `Enter`. Un nome sconosciuto rimane nel composer per poter
essere corretto. Nessun comando è montato automaticamente.

### P0.3 — Una riga di stato che dice qualcosa

**Cosa fa Claude Code.** La status line è uno script configurabile che riceve su
stdin un JSON di sessione e stampa quello che vuole; fra i campi disponibili ci
sono `model.display_name`, `context_window.used_percentage`,
`context_window.context_window_size`, `cost.total_cost_usd`,
`cost.total_duration_ms`, `session_id`, `session_name`
([statusline, "Available data"](https://code.claude.com/docs/en/statusline)).

**Perché conta.** Neuron TUI ha già il widget: `ConversationView` tiene un
`TextWidget` `.status` con gli stati `READY_STATUS`, `WORKING_STATUS` e
`SUGGESTING_STATUS`; il Picker sostituisce i controlli della conversazione con
il proprio footer. È spazio già speso che non dice nulla sulla conversazione.

**Come si mappa.** I dati sui token esistono nel livello Agent 3.15.30:
`ChatHistoryInterface::calculateTotalUsage(): int` restituisce il totale dei
token della History, e `NeuronAI\Chat\Messages\Usage` porta `inputTokens`,
`outputTokens`, `cachedInputTokens`, `reasoningTokens` con `getTotal()`. Il
`SessionProvider` elenca chiave, titolo, ultimo uso e dimensione, ma non espone
una «Session corrente» e non raggiunge direttamente la TUI: dopo ADR 0002 vive
nei comandi che lo usano. Una riga di stato basica può quindi mostrare subito i
token della History; titolo e chiave richiedono invece un nuovo seam o metadati
forniti dalla Host Application. Il costo in dollari **non** è calcolabile senza
un listino: è un dato che appartiene alla Host Application, non a Neuron TUI, e
va lasciato fuori dal milestone basic.

**Costo.** Piccolo, ed è tutto dentro `ConversationView`.

### P1.1 — Approvazione dei tool (human-in-the-loop)

**Cosa fa Claude Code.** È il cuore del suo modello di sicurezza. Le modifiche a
file e i comandi shell richiedono approvazione; la scelta «Yes, don't ask again»
per un comando Bash viene salvata in `.claude/settings.local.json` e vale per le
sessioni successive, mentre l'approvazione di una modifica a file «lasts until
the session ends»; esistono modalità di permesso (`acceptEdits`,
`bypassPermissions`, `dontAsk`, `plan`) e regole `allow`/`ask`/`deny`
([permissions](https://code.claude.com/docs/en/permissions)). Nell'interfaccia si
ciclano con `Shift+Tab`, e `Esc` chiude il dialogo invece di interrompere
([interactive-mode](https://code.claude.com/docs/en/interactive-mode)).

**Perché conta.** È la funzione che rende una TUI agentica usabile su una
macchina vera. Ed è già segnalata come buco *dentro* Neuron TUI:
`ConversationRuntime::tick()` cattura `WorkflowInterrupt` e mostra letteralmente
«Human-in-the-loop interruptions are not supported.» Il messaggio esiste perché
la demo (`examples/demo.php`) monta un `FileSystemToolkit` con un Bash che scrive
davvero.

**Come si mappa — e qui la notizia è buona.** Il livello Agent porta già tutto:

- il middleware `ToolApproval` intercetta la `ToolCallEvent` prima del `ToolNode`
  e lancia un `WorkflowInterrupt` con una `ApprovalRequest` contenente una
  `Action` per tool (`id`, `name`, `description` = gli argomenti in JSON,
  `decision`) — [`ToolApproval` nel tag 3.15.30](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/Middleware/ToolApproval.php);
- `Action` espone `approve()`, `reject($feedback)`, `edit()`, e `ActionDecision`
  ha i casi `Pending`, `Approved`, `Rejected`, `Edit`;
- un tool rifiutato non viene disabilitato ma sostituito da un
  `ToolRejectionHandler` che restituisce al modello «TOOL NOT EXECUTED. The user
  rejected this action. User instruction: …» — quindi il rifiuto è *conversabile*:
  il modello riceve il motivo e prosegue, invece di vedere un errore;
- si registra con `middleware()`/`addMiddleware(ToolNode::class, …)`, e accetta
  sia un elenco di nomi/classi sia una callback condizionale sugli argomenti
  ([Neuron AI — Middleware](https://docs.neuron-ai.dev/agent/middleware));
- la ripresa passa da `Agent::stream($messages, ?InterruptRequest $interrupt)`,
  che `AgentTurn` chiama senza una richiesta di ripresa
  ([Neuron AI — Interruption](https://docs.neuron-ai.dev/workflow/human-in-the-loop)).

Una nota importante sul vincolo di persistenza: la documentazione ufficiale dice
«Persistence is required» perché descrive il caso web, in cui la richiesta viene
salvata e ripresa in un processo diverso. Nel sorgente 3.15.30 osservato,
`Workflow::__construct()` fa `setPersistence($persistence ?? new InMemoryPersistence())`
e solleva eccezione solo se si passa un `resumeToken` senza persistence. **Per una
Conversation TUI, che riprende nello stesso processo e sulla stessa istanza di
Agent, non serve che la Host Application configuri nulla.** Questo è ciò che rende
la funzione fattibile in un milestone basic.

Dal lato TUI il pezzo mancante è uno stato costruito sul `Picker` generico: una
**Richiesta di approvazione** che prende i tasti finché non si è deciso. Le tre
voci basiche
corrispondono a quelle di Claude Code: approva una volta, approva e non chiedere
più per questo tool *in questa Session*, rifiuta con una nota. La versione
persistente delle regole (`allow`/`deny` su file) è fuori scope: Neuron TUI non
legge configurazione, e il posto di quelle regole è la Host Application.

**Costo.** È la raccomandazione più grande della lista, ed è l'unica che tocca
`TurnQueue`/`AgentTurn`/`ConversationRuntime` insieme. Va da sola in uno spec.

### P1.2 — Cronologia dei prompt (`↑`/`↓`, e poi `Ctrl+R`)

**Cosa fa Claude Code.** «Claude Code keeps a history of the prompts you type, and
Up-arrow recall reaches prompts from past sessions of the same project»; la
cronologia è per directory di lavoro, `/clear` apre una sessione nuova ma la
richiamata elenca prima i prompt della nuova e poi quelli delle precedenti, e due
invii identici consecutivi contano come una voce sola. `Ctrl+R` apre la ricerca
inversa; `↑`/`↓` muovono prima il cursore dentro il prompt e navigano la
cronologia solo quando il cursore è già sulla prima o ultima riga visiva
([interactive-mode, "Command history"](https://code.claude.com/docs/en/interactive-mode)).

**Perché conta.** È la funzione che si sente mancare al secondo minuto d'uso. In
Neuron TUI `↑`/`↓` sono `cursor_up`/`cursor_down` dell'`EditorWidget` e basta
quando i Command suggestions non sono aperti.

**Come si mappa.** La regola «prima il cursore, poi la cronologia» è
implementabile sovrascrivendo `getDefaultKeybindings()` in `ComposerEditor` o
passando `setKeybindings()` — le due giunture esistono già ma non sono usate.
La domanda di design vera è *dove vive la cronologia*: Claude Code la lega alla
directory di lavoro, non alla sessione, e la fa attraversare `--clear`. In
Neuron TUI la History appartiene all'Agent e la Session al Session provider,
quindi la cronologia dei prompt non è né l'una né l'altra. La scelta più coerente
col repository è **non inventare una terza persistenza**: dedurre la cronologia
dalle voci `EntryKind::Person` che `entries()` di `HistoryProjection` già produce,
per la Session corrente, e lasciare il "fra Session diverse" al momento in cui
qualcuno lo chiederà. `Ctrl+R` è la versione ricca e può aspettare.

**Costo.** Medio-piccolo se la si limita alla Session corrente; medio se si vuole
attraversare le Session.

### P1.3 — Mostrare il ragionamento del modello

**Cosa fa Claude Code.** L'extended thinking si accende e spegne con
`Option+T`/`Alt+T`, e la status line espone `thinking.enabled`
([interactive-mode](https://code.claude.com/docs/en/interactive-mode),
[statusline](https://code.claude.com/docs/en/statusline)).

**Perché conta.** Con i modelli reasoning, senza questo la TUI resta muta per
decine di secondi mentre il modello pensa, e l'unico segnale è il Working
indicator che conta.

**Come si mappa.** Neuron emette già `ReasoningChunk`, «contains chunks of the
reasoning summary of the model (only available for reasoning models)»
([Neuron AI — Streaming](https://docs.neuron-ai.dev/agent/streaming)). Neuron TUI
lo scarta due volte, di proposito e coerentemente: `AgentTurn::respond()` ignora
tutto ciò che non è `TextChunk`/`ToolCallChunk`/`ToolResultChunk`, e
`HistoryProjection` mappa `ReasoningContent → null`. Renderlo visibile
significa toccare entrambi insieme — altrimenti il vivo e il ridipinto
divergono — più un `EntryKind` e una classe di stile.

**Costo.** Piccolo, ma è un cambio di due moduli, non di uno.

### P1.4 — `/compact`

**Cosa fa Claude Code.** «`/compact [instructions]`: replace history with a
summary, optionally focused on what you specify», accanto a `/clear` e `/context`
([sessions, "Manage context within a session"](https://code.claude.com/docs/en/sessions);
[commands](https://code.claude.com/docs/en/commands)).

**Perché conta.** È il gemello di `/clear` che Neuron TUI già fornisce: `/clear`
riparte senza il contesto precedente, `/compact` ne conserva un riassunto.

**Come si mappa.** Il middleware
[`Summarization`](https://github.com/neuron-core/neuron-ai/blob/3.15.30/src/Agent/Middleware/Summarization.php)
esiste: trova un
punto di taglio che non separa una `ToolCallMessage` dal suo risultato, riassume
il prefisso e ricostruisce la History con il riassunto più gli ultimi
`messagesToKeep` messaggi; si configura con `maxTokens` e `messagesToKeep` e si
attacca ai nodi di inferenza ([Neuron AI — Middleware](https://docs.neuron-ai.dev/agent/middleware)).

**Attenzione — e questo è un vincolo dell'ADR, non un dettaglio.**
`Summarization::summarizeHistory()` chiama `flushAll()` sulla History. L'
[ADR 0001](../adr/0001-sessions-replace-the-agent-chat-history.md) dice
esplicitamente che «`flushAll()` is never called. On a persistent History it
deletes the stored conversation rather than archiving it». Un `/compact` fatto
montando quel middleware **viola la promessa che Neuron TUI non distrugge mai una
conversazione memorizzata**. Se `/compact` si fa, va fatto come *nuova Session che
comincia dal riassunto*, non come compattazione in loco — il che, non a caso, è
la stessa forma di `/clear` che il repository ha già scelto. Questa è la
raccomandazione da discutere prima di implementare.

**Costo.** Medio, quasi tutto di design.

### P2 — Il resto del "basic", in ordine decrescente

- **Realizzato — Command estendibili dalla Host Application.** Claude Code
  ha reso i
  comandi personalizzati un caso di skill: un file Markdown in
  `~/.claude/skills/<nome>/SKILL.md` o `.claude/skills/<nome>/SKILL.md` diventa
  `/<nome>`, con frontmatter (`description`, `argument-hint`, `allowed-tools`,
  `disable-model-invocation`, `user-invocable`…) e sostituzione di `$ARGUMENTS`,
  `$ARGUMENTS[N]`, `$N` ([skills](https://code.claude.com/docs/en/skills)).
  Neuron TUI non copia le skill — quelle sono file di *prompt*, cioè materia
  dell'Agent — ma espone `CommandInterface` e `ConcurrentCommandInterface`: entrambi dichiarano
  nome, descrizione e `run()`, con Controls diversi a seconda che possano girare
  durante un Turn. `Tui::addCommand()` monta un comando, un array o un
  `CommandKitInterface`; il package non ne monta nessuno d'ufficio. La decisione è
  registrata negli ADR 0002 e 0003.
- **`Ctrl+L` ridisegna lo schermo** («Forces a full terminal redraw, keeping input
  and conversation history», [interactive-mode](https://code.claude.com/docs/en/interactive-mode)).
  Quasi gratis: `TerminalInterface::clearScreen()` più
  `Symfony\Component\Tui\Tui::requestRender(true)`.
  Il doppio `Ctrl+L` che equivale a `/clear` è una raffinatezza da lasciare stare.
- **`/export`** («copy the current conversation to your clipboard or save it as a
  plain-text file», [sessions](https://code.claude.com/docs/en/sessions)).
  `entries()` di `HistoryProjection` produce già esattamente la struttura che
  servirebbe; manca solo dove scrivere, e quello è di nuovo un argomento della
  Host Application.
- **Realizzato dal seam pubblico — aprire una Session dalla riga di comando.**
  Claude Code ha `claude --continue`
  / `-c` (riprende la più recente nella directory corrente), `claude --resume` /
  `-r "<sessione>"` (per id o nome, o con picker interattivo), `--name` / `-n` per
  battezzarla e `--fork-session` per biforcarla
  ([sessions, "Resume a session"](https://code.claude.com/docs/en/sessions),
  [cli-reference](https://code.claude.com/docs/en/cli-reference)).
  Neuron TUI non fornisce un eseguibile, e giustamente. La Host Application può
  però risolvere la propria opzione `--resume` attraverso il `SessionProvider`,
  installare la History scelta sull'Agent con `setChatHistory()` e poi passare
  quell'Agent a `Tui::make()`: `ConversationRuntime` dipinge all'avvio la History
  già presente. Non serve quindi un'opzione pubblica aggiuntiva sulla TUI.
- **`/status` e `/context`** ([commands](https://code.claude.com/docs/en/commands)):
  ridondanti se si fa P0.3 bene.
- **`Ctrl+C` in due tempi** — prima svuota il composer, poi esce
  ([interactive-mode](https://code.claude.com/docs/en/interactive-mode)). Oggi
  Neuron TUI esce al primo colpo, il che è più brutale di Claude Code ma anche
  più prevedibile. Cambiarlo solo insieme a P0.1, perché le due semantiche di
  `Esc` e `Ctrl+C` vanno decise insieme.

---

## 4. Fuori scope per un milestone "basic"

Non perché siano poco importanti, ma perché **non sono interfaccia**, oppure
appartengono a un livello che Neuron TUI ha deciso di non toccare.

| Funzione di Claude Code | Perché fuori scope |
|---|---|
| Memoria `CLAUDE.md`, `.claude/rules/`, auto memory ([memory](https://code.claude.com/docs/en/memory)) | È system prompt e istruzioni: materia dell'Agent, che la Host Application configura con `setInstructions()`. Una TUI che legge file di configurazione contraddirebbe la scelta, già presa, che Neuron TUI non scrive e non legge nulla senza che glielo si chieda |
| `settings.json` a cascata, managed settings ([settings/permissions](https://code.claude.com/docs/en/permissions)) | Stessa ragione. Il milestone basic vuole *il dialogo* di approvazione, non il motore di regole |
| Hook (`PreToolUse` ecc.) | Livello Agent; e Neuron ha già middleware e `ObserverInterface`/`EventBus` per lo stesso scopo |
| MCP ([mcp](https://code.claude.com/docs/en/mcp)) | Già in Neuron AI (`src/MCP/McpConnector`, `StdioTransport`, `StreamableHttpTransport`): è la Host Application che collega i server, la TUI non ha nulla da aggiungere |
| Output styles ([output-styles](https://code.claude.com/docs/en/output-styles)) | Modificano il system prompt: Agent, non TUI |
| Checkpoint e `/rewind` ([sessions](https://code.claude.com/docs/en/sessions)) | Richiedono snapshot del codice sul disco. Fuori dal dominio di una conversazione |
| Subagent, agent team, `/background`, `/tasks` | Modello di esecuzione, non interfaccia |
| Modalità shell `!`, menzione file `@` | Presuppongono che la TUI sappia cos'è un filesystem e un repository. Neuron TUI è agnostico rispetto a cosa fa l'Agent |
| Modalità vim, emoji shortcode, dettatura vocale, suggerimenti di prompt | Lusso. `EditorWidget` non offre modalità vim e costruirla è sproporzionato |
| Transcript viewer a schermo intero (`Ctrl+O`), fullscreen rendering | Il componente TUI installato **non ha alternate screen** (§5). Non è una scelta, è un limite |
| Mouse | Idem (§5) |

---

## 5. Cosa dà già `symfony/tui`, e cosa resta da scrivere

Il repository **usa già** `symfony/tui: ^8.1` con `php: ^8.4.1` in
`composer.json`, e `composer.lock` fissa **v8.1.2** (accanto a
`symfony/event-dispatcher` e `symfony/string` 8.1.2). La versione pubblicata più
recente è una circostanza esterna alla baseline: per firme e comportamento fa fede la
**v8.1.2 fissata dal lockfile** ([packagist](https://packagist.org/packages/symfony/tui)).
La domanda "conviene adottarlo" non si pone; le domande utili sono altre tre.

### 5.1 Stato osservato il 18 agosto 2026: sperimentale, e senza documentazione ufficiale

Va detto chiaramente perché ha conseguenze pratiche:

- il `README.md` del pacchetto installato porta il banner standard: «**This
  Component is experimental**. [Experimental features](https://symfony.com/doc/current/contributing/code/experimental.html)
  are not covered by Symfony's [Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html)»;
- il `CHANGELOG.md` alla `8.1` dice «Introduce the component as experimental», e
  **ogni file PHP del pacchetto porta l'annotazione `@experimental`**;
- **la documentazione su symfony.com non esiste ancora**:
  `https://symfony.com/doc/current/tui.html` — l'URL indicato dal README stesso —
  risponde `404`, il componente non compare nell'indice dei componenti, e la PR
  di documentazione [symfony/symfony-docs#22201](https://github.com/symfony/symfony-docs/pull/22201)
  è ancora aperta. Le uniche fonti ufficiali in prosa sono
  [New in Symfony 8.1: Tui Component](https://symfony.com/blog/new-in-symfony-8-1-tui-component)
  e [Introducing the Symfony Tui Component](https://symfony.com/blog/introducing-the-symfony-tui-component).

Conseguenza per Neuron TUI: un aggiornamento anche di patch può rompere. Lo si
vede già — il ramo 8.2 registra un `[BC BREAK]` sul costruttore di
`SelectListWidget`, usato oggi dai Command suggestions. Il Picker generico usa
invece il `PickerList` del package, che tratta ogni choice e i suoi dettagli come
un blocco indivisibile. La copertura a terminale virtuale che il repository già
ha (`VirtualTerminal`) è la difesa giusta, e va estesa a ogni nuovo stato della
TUI.

### 5.2 Coperto gratis dal componente

| Funzione raccomandata | Cosa fornisce già `symfony/tui` 8.1.2 |
|---|---|
| Scorciatoie di editing readline | `EditorWidget` + `Util/KillRing`, `Util/WordNavigator`: `ctrl+a/e/k/u/w/y`, `alt+y`, `alt+b/f`, undo/redo, multilinea. **Già in uso** |
| Nuove scorciatoie (`Esc` di interruzione, `Ctrl+L`, `Ctrl+R`) | `Input/Key` (costanti + `Key::ctrl()`, `alt()`, `shift()`…), `Input/Keybindings::matches()`, `KeyParser` che riconosce anche `shift+enter` e le varianti con modificatori. Nessun binding di default: si dichiarano |
| Dialogo di approvazione dei tool | Il `Picker` generico di Neuron TUI, aperto attraverso `Controls::choose()` con `ChoiceOption`; per choice semplici resta disponibile `SelectListWidget` |
| Pannello tipo `/config` | `SettingsListWidget` + `SettingItem`, oggi inutilizzati |
| Indicatore di attesa annullabile | `LoaderWidget` e `CancellableLoaderWidget` (con `onCancel`), oggi inutilizzati: Neuron TUI ha scritto il proprio `WorkingIndicator` |
| Barre di avanzamento | `ProgressBarWidget`, inutilizzato |
| Ridisegno `Ctrl+L` | `TerminalInterface::clearScreen()`, `Symfony\Component\Tui\Tui::requestRender(true)`, `processRender()` |
| Non bloccare durante un Turn | Loop Revolt + Fiber, `Symfony\Component\Tui\Tui::onTick()` (ritorna `true` = occupato, `false` = inattivo), `scheduleInterval()`. Già sfruttato da `ConversationRuntime::tick()` con `Amp\async()` |
| Ridimensionamento del terminale | `Terminal` registra `EventLoop::onSignal(SIGWINCH, …)` e forza un repaint completo; gestito, con un commento esplicito sui multiplexer che rimandano SIGWINCH al reattach |
| Incolla | Bracketed paste abilitato dal `Terminal`, `BracketedPasteTrait` |
| Tema e stile | `StyleSheet` con selettori, cascata e pseudo-stati, `TailwindStylesheet`, `Style` immutabile con `flex`, `Border`, `Padding` |
| Test senza TTY | `Terminal/VirtualTerminal` (`simulateInput()`, `simulateResize()`, `getOutput()`), `ScreenBuffer`, `TeeTerminal`. Già la base della suite |

Il punto pratico: **quasi tutte le raccomandazioni P0/P1 sono composizione di
widget e binding che esistono già.** Il lavoro è nel dominio della conversazione,
non nel disegno.

### 5.3 Da scrivere comunque

- **Cronologia dei prompt e ricerca inversa**: nessun widget la offre. La regola
  «`↑` muove il cursore finché non sei sulla prima riga, poi naviga la
  cronologia» va implementata a mano sopra `EditorWidget`.
- **Semantica di interruzione del Turn**: `CancellableLoaderWidget` annulla *sé
  stesso*, non un generatore di Neuron. La cancellazione del Turn è codice nostro
  in `AgentTurn`/`TurnQueue`.
- **Contenuto della riga di stato**: il widget c'è, i dati vengono da Neuron e dal
  Session provider.
- **Stato "sto approvando"**: il `Picker` e `ConversationView::isChoosing()`
  esistono già; resta da modellare la richiesta di approvazione e la ripresa
  dell'Agent in `ConversationRuntime`.
- **Tema configurabile**: `Symfony\Component\Tui\Tui::addStyleSheet()` esiste ma
  `ConversationView` non lo espone; `ConversationStyleSheet::create()` è statico. Se si vuole un
  `/theme`, la giuntura va aperta.

### 5.4 Cosa non c'è, e non si ottiene aggiornando dentro `^8.1`

- **Nessun alternate screen.** Il sorgente non contiene `?1049`, `smcup` né
  alcun riferimento a fullscreen: `Render/ScreenWriter` fa *differential
  rendering* riga per riga nello scrollback normale. Il "fullscreen rendering" di
  Claude Code, e con esso il transcript viewer `Ctrl+O` e i tasti `{`/`}`/`[`,
  **non sono riproducibili** con questo componente. Non è una perdita grave: il
  disegno in linea conserva lo scrollback nativo del terminale, che è metà di
  quello che il transcript viewer serve a recuperare.
- **Nessun supporto al mouse nella versione installata.** `StdinBuffer`
  *riconosce* le sequenze mouse legacy e SGR, ma non esiste alcun
  `MouseCoordinator` né alcuna abilitazione del tracking. L'annuncio ufficiale
  8.1 e il post introduttivo parlano di mouse e tab: nel sorgente 8.1.2, e anche
  sul ramo 8.2, quelle classi non ci sono — vanno lette come annunci in
  prospettiva. Le liste `/` e `@` cliccabili di Claude Code sono quindi fuori
  portata.
- **Windows di fatto non supportato**: `Terminal` invoca `stty -g` e
  `stty raw -echo`, e il resize dipende da `SIGWINCH`. Nessun percorso Windows nel
  sorgente. Claude Code documenta invece scorciatoie specifiche per Windows e WSL
  ([interactive-mode](https://code.claude.com/docs/en/interactive-mode)): quel
  livello di parità non è raggiungibile oggi.
- Il multi-select di `SelectListWidget`, con `MultiSelectEvent` e
  `SelectionToggleEvent`, è **solo sul ramo 8.2** — insieme al `[BC BREAK]` sul
  costruttore. Se la Richiesta di approvazione volesse approvare più tool in un
  colpo con delle caselle, quello è il momento in cui servirebbe 8.2; con la sola
  8.1.2 si approva un tool alla volta, che per un milestone basic va bene.

Per completezza: `symfony/console` resta utile alla Host Application (helper
[Cursor](https://symfony.com/doc/current/components/console/helpers/cursor.html),
[QuestionHelper](https://symfony.com/doc/current/components/console/helpers/questionhelper.html),
[ProgressBar](https://symfony.com/doc/current/components/console/helpers/progressbar.html),
[output sections e `SignalableCommandInterface`](https://symfony.com/doc/current/console.html)),
ma è il livello sbagliato per la Conversation TUI e non va mischiato: l'annuncio
ufficiale 8.1 lo dice esplicitamente — «Console stays focused on commands,
arguments and output, while Tui takes over everything related to rich terminal
interaction» ([New in Symfony 8.1: Tui Component](https://symfony.com/blog/new-in-symfony-8-1-tui-component)).

---

## 6. Cosa abilita — e cosa non abilita — il livello Agent

Versione verificata: dichiarata `neuron-core/neuron-ai:^3.15.26`, fissata da
`composer.lock` a **3.15.30**.

**Abilita già, senza modifiche a monte:**

- approvazione dei tool con rifiuto conversabile (`ToolApproval`, `ApprovalRequest`,
  `Action`, `ActionDecision`, `ToolRejectionHandler`), ripresa in-processo senza
  persistenza configurata ([Middleware](https://docs.neuron-ai.dev/agent/middleware),
  [Interruption](https://docs.neuron-ai.dev/workflow/human-in-the-loop));
- riassunto del contesto (`Summarization`), con la riserva sull'ADR in §3 P1.4;
- to-do del modello (`TodoPlanning` + `WriteTodosTool`) — la demo lo monta già:
  è il corrispettivo del `Ctrl+T` «Toggle Claude's task checklist» di Claude Code
  ([interactive-mode](https://code.claude.com/docs/en/interactive-mode)), e oggi
  la TUI lo mostra soltanto come una normale attività di tool;
- ragionamento in streaming (`ReasoningChunk`) e conteggio token
  (`Usage`, `calculateTotalUsage()`);
- MCP e toolkit (`FileSystem`, `Calculator`, `Calendar`, …), che restano affari
  della Host Application ([Tools](https://docs.neuron-ai.dev/agent/tools)).

**Non abilita, e va progettato attorno:**

- **elencare le conversazioni esistenti**: `ChatHistoryInterface` ha cinque metodi
  e nessuno è una chiave. È esattamente la ragione per cui esiste il Session
  provider, come dice l'[ADR 0001](../adr/0001-sessions-replace-the-agent-chat-history.md).
  Rinominare o cancellare una Session — cioè `/rename` e il `Ctrl+R` del picker di
  Claude Code ([sessions](https://code.claude.com/docs/en/sessions)) — richiede
  operazioni nuove sul Session provider, e la promessa «Neuron TUI never deletes a
  stored conversation» va rinegoziata prima, non dopo;
- **costo in dollari**: nessun listino nel framework. Fuori scope;
- **progresso incrementale dentro un tool**: il contratto consegna solo il
  risultato finale, come già documentato in
  [`neuron-non-blocking-tools.md`](neuron-non-blocking-tools.md).
  Una TUI non può mostrare lo stdout di un Bash mentre gira, e questo è il limite
  più visibile rispetto a Claude Code.

---

## 7. Riepilogo operativo aggiornato

| Funzione | Stato | Prossimo vincolo |
|---|---|---|
| `/help` | realizzato, da montare esplicitamente | nessuno |
| Command suggestions | realizzati | nessuno |
| Command estendibili | realizzati con `CommandInterface`, `ConcurrentCommandInterface` e `CommandKitInterface` | nessuno |
| Aprire una Session all'avvio | già consentito configurando la History dell'Agent prima di `Tui::make()` | la CLI appartiene alla Host Application |
| `Esc` interrompe il Turn | P0, da fare | semantica della risposta parziale e della coda |
| Riga di stato con Session e token | P0, da fare | accesso ai metadati della Session corrente |
| Approvazione dei tool | P1, da fare | serve uno spec proprio |
| Cronologia dei prompt | P1, da fare | decisione su dove vive |
| Ragionamento visibile | P1, da fare | vivo e ridipinto devono coincidere |
| `/compact` | P1, da progettare | **in conflitto con l'ADR 0001** se modifica la History in loco |
| `Ctrl+L` | P2, da fare | nessuno |
| `/export` | P2, da progettare | la destinazione appartiene alla Host Application |

Interruzione, status e approvazione restano il nucleo del salto di qualità. La
compattazione non è solo implementazione: richiede prima una decisione sul
rapporto fra riassunto e Session persistente.

---

## Fonti

**Claude Code (Anthropic, ufficiali).** [Interactive mode](https://code.claude.com/docs/en/interactive-mode) ·
[Commands](https://code.claude.com/docs/en/commands) ·
[Skills](https://code.claude.com/docs/en/skills) ·
[Manage sessions](https://code.claude.com/docs/en/sessions) ·
[Configure permissions](https://code.claude.com/docs/en/permissions) ·
[Status line](https://code.claude.com/docs/en/statusline) ·
[Memory](https://code.claude.com/docs/en/memory) ·
[Output styles](https://code.claude.com/docs/en/output-styles) ·
[CLI reference](https://code.claude.com/docs/en/cli-reference) ·
[MCP](https://code.claude.com/docs/en/mcp)

**Neuron AI (ufficiali).** [Middleware](https://docs.neuron-ai.dev/agent/middleware) ·
[Human in the loop / Interruption](https://docs.neuron-ai.dev/workflow/human-in-the-loop) ·
[Streaming](https://docs.neuron-ai.dev/agent/streaming) ·
[Chat history and memory](https://docs.neuron-ai.dev/agent/chat-history-and-memory) ·
[Tools](https://docs.neuron-ai.dev/agent/tools) · sorgente 3.15.30 verificato
durante la ricerca; `composer.lock` conserva versione e commit osservati.

**Symfony (ufficiali).** [packagist symfony/tui](https://packagist.org/packages/symfony/tui) ·
[packagist symfony/console](https://packagist.org/packages/symfony/console) ·
[New in Symfony 8.1: Tui Component](https://symfony.com/blog/new-in-symfony-8-1-tui-component) ·
[Introducing the Symfony Tui Component](https://symfony.com/blog/introducing-the-symfony-tui-component) ·
[Experimental features](https://symfony.com/doc/current/contributing/code/experimental.html) ·
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html) ·
[symfony-docs PR #22201 (aperta)](https://github.com/symfony/symfony-docs/pull/22201) ·
[Console](https://symfony.com/doc/current/console.html) e i suoi helper · sorgente
`symfony/tui` v8.1.2 verificato durante la ricerca e versione fissata in
`composer.lock`.

**Interne al repository.** `CONTEXT.md`, `README.md`,
[ADR 0001](../adr/0001-sessions-replace-the-agent-chat-history.md),
[ADR 0002](../adr/0002-the-conversation-tui-mounts-nothing-on-its-own.md),
[ADR 0003](../adr/0003-the-tui-is-composed-around-a-required-agent.md),
[ADR 0004](../adr/0004-file-sessions-use-key-named-json.md),
[`neuron-non-blocking-tools.md`](neuron-non-blocking-tools.md),
`src/` alla revisione `978e649`.
