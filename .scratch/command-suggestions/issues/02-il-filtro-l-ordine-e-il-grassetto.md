# 02 — Il filtro, l'ordine e il grassetto

**What to build:** La lista si restringe a quello che si sta scrivendo, e dice
perché ogni riga è rimasta.

Una riga resta se quello che è stato scritto compare **dentro il nome**, in un
punto qualsiasi e di fila: `/wind` trova `/rewind`, `/ses` trova `/sessions`,
`/rwd` non trova niente. Maiuscole e minuscole non contano. La descrizione non
entra nella ricerca: si cerca fra i nomi, non fra i significati.

Quello che resta è ordinato in tre insiemi in fila — prima le corrispondenze
esatte, poi quelle che cominciano per quanto scritto, poi le altre — e dentro
ciascuno vale l'ordine in cui la Host Application ha montato i comandi. Non è
un punteggio: chi legge il codice deve poter prevedere l'ordine senza eseguirlo.

Dentro ogni nome, il tratto che corrisponde è in grassetto, così si vede a
colpo d'occhio perché quella riga è lì. Con la sottostringa contigua il tratto
è sempre uno solo. Quando non resta nessuno, al posto della lista compare la
riga che lo dice, con quanto è stato scritto.

**Blocked by:** 01 — La lista compare mentre si scrive un nome.

**Status:** resolved

- [x] Scrivendo dopo lo slash la lista tiene solo i comandi il cui nome
      contiene quello che si è scritto
- [x] La corrispondenza è insensibile a maiuscole e minuscole
- [x] Una sottostringa in mezzo al nome trova il comando; lettere non contigue
      no
- [x] La descrizione non fa comparire un comando che il nome non farebbe
      comparire
- [x] Il nome scritto per intero è la prima riga, davanti a un comando che lo
      contiene
- [x] Un comando che comincia per quanto scritto sta davanti a uno che lo
      contiene soltanto
- [x] A parità di corrispondenza l'ordine è quello di montaggio
- [x] Il tratto corrispondente compare in grassetto dentro il nome
- [x] Quando nessun comando resta, compare la riga `No commands match "/…"`
      con quanto è stato scritto
- [x] Cancellando, la lista si riallarga

## Acceptance

Il filtro, i tre insiemi e il grassetto vivono in
`src/Tui/CommandSuggestions.php`: le righe non sono più calcolate una volta
sola al montaggio ma a ogni bozza, e la lista riceve un insieme nuovo solo
quando cambia davvero, così la selezione torna in cima con esso. Ogni casella
qui sopra è verificata da un test in `tests/NeuronCliTest.php`, dal solo seam
previsto dalla spec: TUI su `VirtualTerminal`, tasti simulati, schermo letto
(quando serve senza codici ANSI).

- `testTheSuggestionsNarrowToWhatIsBeingWritten` — `/hel` tiene `/help` e
  lascia fuori `/exit`.
- `testDeletingWidensTheSuggestionsAgain` — cancellando torna anche `/exit`.
- `testTheMatchIgnoresTheCaseOfWhatIsWritten` — `/HEL` trova `/help`.
- `testAStretchInTheMiddleOfANameFindsTheCommand` — `/wind` trova `/rewind`,
  `/rwd` porta alla riga `No commands match "/rwd"`.
- `testADescriptionNeverBringsACommandIn` — un comando descritto «Rewinds the
  conversation.» non compare per `/rewind`.
- `testTheWholeNameIsTheFirstLine` — `/ses` mette `/ses` davanti a
  `/sessions`, montato prima.
- `testANameThatBeginsWithWhatIsWrittenComesFirst` — `/window` davanti a
  `/rewind`.
- `testCommandsMatchingAlikeKeepTheirMountingOrder` — a parità vale il
  montaggio.
- `testTheThreeSetsAreReadOneAfterTheOther` — una bozza sola che produce
  tutti e tre gli insiemi, in fila.
- `testANameLongerThanItsLineIsMatchedAllTheSame` — un nome più lungo della
  riga su cui si legge è cercato per intero lo stesso.
- `testTheMatchingStretchIsBoldInsideTheName` — il tratto è in grassetto
  dentro il nome.

Verifica: `composer test` (151 test, 523 assert) e `composer stan` (due
configurazioni) verdi sul commit del branch.

Tre punti decisi durante la revisione, con la ragione:

- **Si cerca nel nome intero, si evidenzia in quello che si legge.** Il nome
  è tenuto tre volte: quello a cui il comando risponde (che un completamento
  scriverà, ticket 03), quello reso sicuro per la ricerca, e quello
  accorciato a `NAME_WIDTH` su cui si legge la riga. Se il tratto che
  corrisponde cade nella parte tagliata, la riga resta senza grassetto:
  c'è per aver corrisposto, e il grassetto non può indicare ciò che non si
  vede.
- **Il grassetto è un `Style(bold: true)` costruito qui**, non una regola
  dello style sheet: lo style sheet veste righe intere, e questo è mezzo
  nome.
- **La riga «No commands match» azzera anche la lista tenuta da parte**, così
  che tornando a una bozza che corrispondeva la selezione riparta dall'alto
  invece di riprendere dov'era.
