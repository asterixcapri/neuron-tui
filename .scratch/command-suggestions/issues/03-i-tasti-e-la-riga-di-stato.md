# 03 — I tasti e la riga di stato

**What to build:** Si sceglie una riga e la si fa scrivere al posto proprio,
senza che nessun tasto cambi il significato che aveva prima.

↑↓ muovono la riga scelta mentre la lista è aperta — cosa che al composer non
toglie niente, perché lì la bozza è per definizione una riga sola — e la scelta
torna in cima ogni volta che l'insieme delle righe mostrate cambia: chi digita
sta restringendo, non scorrendo. Tab scrive nel composer il nome scelto seguito
da uno spazio, il che chiude la lista da sé e lascia il cursore dove si
scrivono gli argomenti. Dove non c'è niente da completare — la riga «No
commands match», o la lista chiusa — Tab non fa niente, e in particolare non
infila mai una tabulazione nella bozza.

Esc chiude la lista lasciando intatto quello che si stava scrivendo; una
seconda Esc svuota la bozza, come ha sempre fatto. **Invio non viene mai
intercettato:** manda quello che è scritto, lista aperta o chiusa che sia, e
chi ha scritto il nome per intero non deve batterlo due volte.

Mentre la lista è aperta, la riga di stato in fondo nomina i tasti che hanno
senso adesso — muoversi, completare, mandare — e torna a quella di prima appena
la lista si chiude. È il mestiere che quella riga svolge già negli altri stati.

**Blocked by:** 01 — La lista compare mentre si scrive un nome; 02 — Il filtro,
l'ordine e il grassetto.

**Status:** resolved

- [x] ↑↓ muovono la riga scelta senza spostare il cursore nel composer
- [x] La scelta torna in cima ogni volta che l'insieme delle righe mostrate
      cambia
- [x] Tab scrive nel composer il nome scelto seguito da uno spazio
- [x] Dopo il completamento la lista non è più sullo schermo e si possono
      scrivere gli argomenti
- [x] Tab non fa niente quando nessun comando corrisponde, e non inserisce mai
      una tabulazione
- [x] Tab non fa niente quando la lista è chiusa
- [x] Esc chiude la lista lasciando la bozza; una seconda Esc la svuota
- [x] Invio manda quello che è scritto anche a lista aperta, e il comando parte
- [x] La riga di stato nomina i tasti mentre la lista è aperta, e torna com'era
      dopo

## Acceptance

I tasti sono presi fra i listener globali della TUI — quelli che girano prima
del widget che ha il focus — registrati dalla `ConversationView` stessa a
priorità 50, cioè dopo il `Ctrl+C` e le pagine su e giù che monta
`NeuronCli` (priorità 100). Il focus resta al composer per tutto il tempo:
niente viene sospeso e la lista non lo prende mai. Con il Picker aperto il
listener si tira indietro alla prima riga, che è anche il posto dove la
regola «una cosa sola da guardare» resta scritta.

`CommandSuggestions` risponde ora a due domande, e le due bastano a decidere
cosa fa ciascun tasto: `isOnScreen()` — c'è qualcosa nella fascia, riga «No
commands match» compresa — e `isListOpen()` — quel qualcosa è una lista da
cui scegliere. Da lì: `choosePrevious()`/`chooseNext()` girano attorno agli
estremi come fa il widget di lista quando i tasti li tiene lui, `chosenName()`
dice il nome che Tab scriverebbe, `dismiss()` toglie la fascia lasciando la
bozza. La scelta torna in cima dove già la lista riceveva righe nuove, quindi
non c'è un secondo posto in cui ricordarsene.

Tab scrive `nome + spazio` con `ComposerEditor::writeDraft()`, che è
`setText()` più un salto a fine riga: `setText()` da solo riporta il cursore
davanti a quello che ha appena scritto, e il punto di tutto è che il cursore
resti dove si scrivono gli argomenti. Lo stesso metodo serve `emptyComposer()`,
così l'unico modo di scrivere nel composer dall'interno passa da un posto solo
e da lì le suggestions vengono avvisate.

La riga di stato è ora derivata invece che assegnata: `showStatus()` sceglie
fra le quattro secondo lo stato — Picker, lista aperta, Turn in volo, pronto —
e viene chiamata da ogni punto che quello stato lo cambia. Perciò a lista
chiusa la riga torna da sé a quella di prima, `WORKING_STATUS` compresa se un
Turn è ancora in volo: «torna a `READY_STATUS`» della spec vale fuori dal
Turn, e dentro non avrebbe senso mentire.

Due scelte prese qui, dove la spec lasciava spazio:

- **Esc toglie anche la riga «No commands match».** La spec elenca i casi in
  cui Tab non fa niente — quella riga e la lista chiusa — e per Esc non elenca
  niente: la fascia occupata copre la conversazione tanto quanto la lista.
  Costo: sei prove già esistenti che dopo un comando sconosciuto battevano un
  Esc solo per svuotare il composer ora ne battono due, ed è aggiornato lì,
  con il perché a fianco.
- **La riga di stato non nomina i tasti sopra quella riga.** Lì non c'è niente
  da muovere né da completare, e una riga di stato che promette Tab dove Tab
  non fa niente sarebbe la promessa falsa che il ticket 04 ha appena tolto
  dalla lista. Sopra la riga «No commands match» la riga di stato è quella di
  prima.

Verificato dal solo seam previsto dalla spec — TUI su `VirtualTerminal`, tasti
simulati, schermo letto senza codici ANSI, in `tests/NeuronCliTest.php`:

- `testTheArrowsChooseALineWithoutMovingTheCursor` — ↑ e poi una lettera: la
  bozza è `/alp`, non `p/al`, cioè il cursore non si è mosso;
- `testTheArrowsMoveTheChosenLine` — ↓ e la freccia è sulla seconda riga;
- `testTheArrowsReachTheLastOfMoreCommandsThanFit` — con dieci comandi e otto
  righe visibili, ↑ arriva all'ultimo e il contatore dice `(10/10)`;
- `testTheChosenLineGoesBackToTheTopWhenTheLinesChange` — scesi di due righe,
  una lettera in più riporta la freccia in cima;
- `testTabWritesTheChosenNameAndTheArgumentsFollowIt` — Tab scrive `/album `,
  la lista sparisce, e `now` battuto dopo arriva al comando come argomento;
- `testTabWritesNothingWhenNoCommandMatches` — sulla riga «No commands match»
  Tab non scrive niente, tabulazione compresa;
- `testTabWritesNothingWhileTheListIsClosed` — quello che parte per l'Agent è
  `hello`, tale e quale;
- `testEscapeClosesTheListAndTheNextOneEmptiesTheDraft` e
  `testEscapeAlsoTakesAwayTheLineThatSaysNothingMatches` — il primo Esc toglie
  la fascia e lascia la bozza, il secondo la svuota;
- `testTheStatusLineNamesTheKeysWhileTheListIsOpen` — a lista aperta la riga
  nomina ↑↓, Tab e Invio, e uno spazio la riporta a `READY_STATUS`;
- `testEnterStillSendsWhileTheSuggestionsAreOpen`, già in piedi dal ticket 01,
  copre l'Invio mai intercettato.

Il README dice ora i tasti nuovi, nella sezione «Keys» e nel paragrafo sulle
suggestions, Esc compreso nella sua forma a due battute.

Verifica: `composer test` (164 test, 572 assert) e `composer stan` (due
configurazioni) verdi sul commit del branch. Nota d'ambiente, la stessa del
ticket 04: la cache condivisa di PHPStan in `/tmp/phpstan` viene da altri
worktree, e le due configurazioni sono verdi eseguendole con un `TMPDIR`
proprio.
