# 03 — Il Picker serve a scegliere qualsiasi cosa

**What to build:** Un comando può offrire un elenco e sapere cosa è stato
scelto. Fra i Controls compare il verbo che apre il **Picker**: prende un
titolo e delle coppie chiave/etichetta, aspetta, e restituisce la chiave
scelta — oppure niente, se la persona ha annullato.

Il Picker smette di conoscere le Session. Diventa uno stato in cui la
Conversation TUI è mentre una persona sceglie da un elenco qualunque: frecce
per muoversi, digitazione per restringere, invio per scegliere, escape per
annullare, e il composer che nel frattempo non prende testo. Il titolo
compare nelle istruzioni, così un elenco di modelli non dice "Sessions". Il
comando che elenca le Session continua a funzionare traducendo da sé le
Session in etichette.

È l'unico verbo dei Controls che aspetta. Il widget di lista sottostante è già
generico, quindi il lavoro sta nel non specializzarlo più e nel far aspettare
il comando senza fermare tutto il resto della TUI: lo schermo continua a
ridisegnarsi mentre la scelta è aperta.

**Blocked by:** 02 — La Host Application può montare un suo comando.

**Status:** resolved

- [x] Un comando montato dalla Host Application può offrire un elenco di sua
      scelta e riceve la chiave selezionata
- [x] Annullare restituisce al comando l'assenza di scelta, e il comando può
      distinguerla da una scelta fatta
- [x] Il titolo passato dal comando compare nelle istruzioni dell'elenco
- [x] Frecce, filtro da tastiera, invio ed escape si comportano come oggi
- [x] Mentre l'elenco è aperto il composer non prende testo
- [x] Mentre l'elenco è aperto la Conversation TUI continua a ridisegnarsi
- [x] Il comando che elenca le Session continua a funzionare come prima
- [x] Il Picker non conosce più le Session

## Answer

Implementato sul branch `ticket/03-picker-generico`.

`Controls::choose(string $title, array $options): ?string` è l'unico verbo che
aspetta: apre il Picker e torna la chiave della riga scelta, o `null` se la
persona ha annullato. Sotto, `ConversationView::choose()` costruisce un
`Amp\DeferredFuture`, apre il Picker e fa `await()`. Il ponte sull'event loop
funziona: `symfony/tui` e amphp girano entrambi su `revolt/event-loop`, e la
callback di input che esegue il comando gira già dentro una fiber propria,
quindi la sospensione ferma solo il comando — il loop continua a ticchettare e
lo schermo a ridisegnarsi (provato catturando l'elenco a video mentre il
comando è ancora sospeso).

Un punto è emerso solo scrivendo il codice: uscire dalla TUI mentre l'elenco è
aperto lasciava il comando sospeso per sempre, perché la sospensione di
`Tui::run()` interrompe il loop prima che la continuation della fiber venga
eseguita. Quindi `ConversationView::stop()`, se una scelta è aperta, la
risponde con `null` e ricorda di uscire: l'uscita vera avviene quando `choose()`
riprende. Coperto da un test.

`SessionPicker` è diventato `Picker` (`src/Tui/Picker.php`): prende titolo e
coppie chiave/etichetta, non nomina più `Session`, e il titolo compare nelle
istruzioni. Le classi CSS `session-picker`/`session-list` sono diventate
`picker`/`picker-list`; la colonna della descrizione (la data di ultimo uso
delle Session) sparisce, come previsto dal design doc che passa al Picker solo
coppie chiave/etichetta. Il comando interno `/sessions` traduce da sé le
Session in etichette e riapre quella scelta.

Verifica: `composer test` 117 test / 388 asserzioni verdi, `composer stan`
(due configurazioni) senza errori.

