# 02 — La Host Application può montare un suo comando

**What to build:** Una Host Application può dire alla Conversation TUI cosa si
può digitare. Costruendo la TUI passa i propri Slash command; quando qualcuno
ne digita il nome, il comando viene eseguito e riceve gli argomenti che lo
seguivano.

Un comando dichiara il nome per cui risponde, slash incluso, e una riga che lo
descrive. Mentre gira riceve i **Controls**: può dire qualcosa nella
conversazione, avvertire che qualcosa non è andato, mandare un prompt
all'Agent — che parte come se l'avesse scritto la persona, e la cui risposta
compare sullo schermo, non torna al comando — raggiungere l'Agent per
cambiargli modello, istruzioni o tool con l'API di Neuron AI, e far uscire dal
terminale. I widget della Conversation TUI restano irraggiungibili da qui.

I tre comandi che la TUI ha oggi restano dove sono e continuano a funzionare
come prima: questo ticket aggiunge la possibilità di montarne altri, non
toglie niente.

Da qui in avanti la Conversation TUI esegue codice che non è suo, quindi
comprende la protezione: quello che un comando lascia risalire diventa una
riga di errore nella conversazione e il terminale resta vivo, come già accade
per un'eccezione durante un Turn.

Due comandi che rispondono allo stesso nome fermano la costruzione della TUI
con un errore, invece di lasciar vincere silenziosamente uno dei due.

**Blocked by:** 01 — L'input produce nome e argomenti.

**Status:** resolved

- [x] Un comando montato dalla Host Application viene eseguito quando se ne
      digita il nome
- [x] Il comando riceve gli argomenti scritti dopo il nome
- [x] Ciò che un comando dice, e ciò di cui avverte, compare nella
      conversazione
- [x] Il prompt che un comando manda all'Agent produce una risposta sullo
      schermo
- [x] Un comando può raggiungere l'Agent e cambiargli provider, istruzioni o
      tool
- [x] Un comando può far uscire dal terminale
- [x] Un comando che va in errore lascia una riga di errore e il terminale
      resta utilizzabile
- [x] Due comandi con lo stesso nome fermano la costruzione della Conversation
      TUI
- [x] I tre comandi esistenti si comportano esattamente come prima

## Esito

`NeuronCli` accetta `commands:` e monta la lista una volta, indicizzata per
nome. Un comando implementa `SlashCommand` — `name()`, `describe()` da
`Command`, e `run(Controls $controls, string $arguments)` — e riceve gli
argomenti che seguivano il nome, stringa vuota quando non ce n'erano.

I `Controls` portano i cinque verbi che il ticket nomina: `say()` (una riga
nella conversazione, nuovo `ConversationView::showNotice()`), `warn()` (la
riga di errore che già esisteva), `ask()` (che passa dalla stessa strada di
un messaggio digitato: coda dei Turn, riga della persona sullo schermo, e la
risposta arriva sullo schermo, non al comando), `agent()` e `stop()`. Nessun
widget della Conversation TUI è raggiungibile da lì. `choose()`, `useAgent()`
e `commands()` restano ai ticket 03, 04 e 06.

L'esecuzione è protetta: quello che un comando lascia risalire diventa la
stessa riga di errore di un'eccezione durante un Turn — ora entrambe passano
per `NeuronCli::showFailure()` — e il terminale resta utilizzabile.

Il montaggio rifiuta due comandi con lo stesso nome, e anche un comando che
prende uno dei tre nomi che la TUI risponde da sé: sono due
`InvalidArgumentException` con messaggi distinti, perché nel secondo caso non
c'è un secondo comando da cercare. L'enum dei tre è rinominato
`BuiltInCommand`, così che `SlashCommand` sia l'interfaccia; i tre si
comportano come prima, e come loro un comando montato viene rifiutato durante
un Turn.

`NeuronCli\Conversation\Command`, `SlashCommand` e `Controls` entrano fra i
moduli pubblici (`tools/phpstan/PublicModulePolicy.php`) e nel README, dove il
montaggio è documentato con un esempio.

Prove, dal terminale virtuale in `tests/NeuronCliTest.php`: comando montato
eseguito con i suoi argomenti; `say` e `warn` sullo schermo; il prompt di
`ask` che produce una risposta; `agent()` che cambia provider, istruzioni e
tool; `stop()` che esce senza Ctrl+C; un comando che va in errore seguito da
una domanda a cui l'Agent risponde; il rifiuto durante un Turn; e le due
costruzioni fermate dai nomi in conflitto.

Verifica: `composer test` 113 test, 371 asserzioni, verde; `composer stan`
(due configurazioni) senza errori.

Debito lasciato ai ticket seguenti: la ricerca del nome è ancora in due passi
(registro, poi enum), `describe()` non è letto da nessuno finché non arriva
`/help`, e la riconciliazione dello schermo dopo un comando è del ticket 05.
