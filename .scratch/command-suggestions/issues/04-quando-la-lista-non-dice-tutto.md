# 04 — Quando la lista non dice tutto

**What to build:** La lista non promette mai qualcosa che non succederà.

Mentre l'Agent lavora, la Conversation TUI respinge i comandi che non
dichiarano nel tipo di girare durante un Turn, e lo dice con una riga di
errore. Suggerire quei nomi proprio allora sarebbe una promessa falsa: finché
un Turn è in volo la lista contiene i soli comandi eseguibili in quel momento,
e se la Host Application non ne ha montato nessuno si finisce sulla riga che
dice che non corrisponde niente. Finito il Turn, la lista torna intera.

Mentre un Picker è aperto non compare invece nessuna lista: lì il composer non
prende testo, quindi non c'è nessuna bozza da completare, e le due cose non
sono mai sullo schermo insieme.

**Blocked by:** 01 — La lista compare mentre si scrive un nome.

**Status:** resolved

- [x] Mentre l'Agent lavora, la lista contiene i soli comandi eseguibili
      durante un Turn
- [x] Un comando che verrebbe respinto non compare nella lista mentre l'Agent
      lavora
- [x] Se nessun comando è eseguibile durante un Turn, compare la riga
      `No commands match "/…"`
- [x] Finito il Turn, la lista torna a contenere tutti i comandi montati
- [x] Mentre un Picker è aperto non compare nessuna lista

## Acceptance

La lista è ora letta dalla bozza e dal Turn insieme:
`src/Tui/CommandSuggestions.php` tiene due insiemi di righe — tutti i comandi
montati e i soli `RunsWhileWorking` — e sceglie fra i due secondo lo stato,
che la `ConversationView` gli dice da `working()` e da `ready()`, cioè dagli
stessi due punti in cui cambia la riga di stato. Il criterio del filtro è lo
stesso `instanceof RunsWhileWorking` con cui `NeuronCli::carryOut()` respinge
un comando a metà Turn, quindi la lista non può promettere ciò che verrebbe
rifiutato. Fuori dal Turn niente cambia: la lista è quella di 01.

Verificato dal solo seam previsto dalla spec — TUI su `VirtualTerminal`,
tasti simulati, schermo letto senza codici ANSI, in `tests/NeuronCliTest.php`:

- `testTheListIsNarrowedToWhatRunsForAsLongAsTheTurnLasts` — con `/probe`
  (respinto) e `/pulse` (`RunsWhileWorking`) montati: mentre l'Agent risponde
  la lista ha `/pulse` e non `/probe`; finito il Turn, sotto lo stesso `/`
  ancora nel composer, ci sono di nuovo tutti e due. Copre le prime due
  caselle e la quarta.
- `testWhileTheAgentWorksNothingMatchesWithoutACommandThatRunsThen` — montato
  il solo `/probe`, a metà Turn compare `No commands match "/"`.
- `testNoSuggestionsAreShownWhileAPickerIsOpen` — mentre il Picker è aperto
  sullo schermo c'è la sola lista del Picker.

Tre punti osservati in revisione e lasciati come sono, con la ragione:

- Il Picker non ha avuto bisogno di una riga di codice: aprendolo si svuota
  il composer (`emptyComposer()`), e da lì in poi il composer non prende
  testo, quindi non c'è nessuna bozza e nessuna lista. La prova quindi
  descrive più che vincolare — nessuna implementazione delle suggestions
  potrebbe mostrare qualcosa lì — ed è tenuta perché la casella chiede che
  le due cose non siano mai insieme sullo schermo.
- `working()` chiede di nuovo cosa mostrare come fa `ready()`, benché oggi un
  Turn cominci sempre con il composer già svuotato: è una riga che tiene i
  due estremi del Turn identici invece di far dipendere le suggestions dal
  fatto che qualcun altro abbia svuotato la bozza.
- La regola «gira mentre l'Agent lavora» resta scritta come `instanceof
  RunsWhileWorking` nei due posti che la usano — il rifiuto in `NeuronCli` e
  il filtro qui — perché è il meccanismo che ADR 0002 mette nel tipo del
  comando, e un predicato condiviso vorrebbe dire toccare `NeuronCli` per
  niente.

Verifica: `composer test` (143 test, 507 assert) e `composer stan` (due
configurazioni) verdi sul commit del branch. Nota d'ambiente: la cache
condivisa di PHPStan in `/tmp/phpstan` era stata scritta da un altro
worktree e faceva fallire l'analisi con errori interni su percorsi phar
altrui; le due configurazioni sono verdi eseguendole con un `TMPDIR` proprio.
