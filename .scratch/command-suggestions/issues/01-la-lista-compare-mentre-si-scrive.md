# 01 — La lista compare mentre si scrive un nome

**What to build:** Chi scrive un nome dopo lo slash vede i comandi che potrebbe
scrivere. Appena il composer contiene una riga sola che comincia con `/`,
sopra la riga del composer compare una fascia con i comandi montati: per
ciascuno il nome e la riga che usa per descriversi. La fascia se ne va da sé
quando quello che si sta scrivendo non è più un nome — uno spazio, un a capo,
lo slash cancellato — e non compare affatto per uno slash in mezzo a un
messaggio, che resta testo per l'Agent.

Sono le Command suggestions di `CONTEXT.md`, e non sono il Picker: nessuno
viene sospeso, il focus e i tasti restano al composer, la bozza resta dov'era.
Il widget di lista sottostante è lo stesso, il resto no.

Questo ticket non filtra niente: la fascia mostra tutti i comandi montati,
nell'ordine in cui la Host Application li ha nominati. Se non ce n'è nessuno,
mostra la riga che dice che non corrisponde niente — che è anche quello che
vedrà chi ha montato una TUI senza comandi.

**Blocked by:** None — can start immediately.

**Status:** resolved

- [x] Digitando `/` compaiono tutti i comandi montati, ciascuno con la sua
      descrizione
- [x] La fascia sta sopra la riga del composer, e il composer continua a
      prendere il testo che si scrive
- [x] Sono visibili otto righe, con l'indicatore di scorrimento quando i
      comandi non ci stanno
- [x] Uno spazio, un a capo o lo slash cancellato fanno sparire la fascia
- [x] Uno slash in mezzo a un messaggio non fa comparire niente
- [x] Su una TUI senza comandi montati compare la riga `No commands match "…"`
      invece della lista
- [x] Invio continua a mandare quello che è scritto, con la fascia aperta

## Acceptance

Implementato in `src/Tui/CommandSuggestions.php`, montato dalla
`ConversationView` accanto allo slot del Picker e alimentato dai comandi che
`NeuronCli` ha montato. Ogni casella qui sopra è verificata da un test in
`tests/NeuronCliTest.php`, dal solo seam previsto dalla spec: TUI su
`VirtualTerminal`, tasti simulati, schermo letto senza codici ANSI.

- `testWritingASlashShowsTheMountedCommandsWithTheirDescriptions` — nomi e
  descrizioni, nell'ordine di montaggio.
- `testTheSuggestionsSitAboveAComposerThatKeepsTakingText` — la fascia è
  sopra la riga del composer, che intanto continua a prendere il testo.
- `testMoreCommandsThanFitAreCountedRatherThanDropped` — otto righe visibili
  su dieci comandi, con il contatore `(1/10)`.
- `testASpaceANewLineOrADeletedSlashTakesTheSuggestionsAway`.
- `testASlashInTheMiddleOfAMessageSuggestsNothing`.
- `testATerminalWithoutCommandsSaysNothingMatches` — `No commands match "/"`.
- `testEnterStillSendsWhileTheSuggestionsAreOpen` — Invio manda quello che è
  scritto e, con la bozza, se ne va anche la fascia.

Note per i ticket successivi: la lista non è mai messa a fuoco e nessun tasto
è intercettato, quindi 03 aggiunge i listener globali senza toglierli a
nessuno; le righe sono calcolate una volta sola dai comandi montati, e 02 e 04
sono i due ticket che le faranno dipendere dalla bozza e dal Turn.

Verifica: `composer test` (140 test, 494 assert) e `composer stan` (due
configurazioni) verdi sul commit del branch.

Due punti osservati in revisione e lasciati come sono, con la ragione:

- Le otto righe sono otto voci: `VISIBLE_LINES` va al widget di lista come
  `maxVisible`, che conta le voci, e l'indicatore di scorrimento si aggiunge
  sotto quando serve — otto righe di comandi più la riga del contatore, che è
  quello che la casella chiede e quello che il Picker fa già con la stessa
  costante.
- La bozza è letta byte per byte (`preg_match` senza `u`): quello che è
  stato incollato nel composer sono byte che nessuno ha validato, e la
  domanda «si sta scrivendo un nome?» deve avere una risposta anche lì
  invece di fallire sulla codifica.
