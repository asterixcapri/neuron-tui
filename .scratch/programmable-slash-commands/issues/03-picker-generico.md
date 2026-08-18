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

**Status:** ready-for-agent

- [ ] Un comando montato dalla Host Application può offrire un elenco di sua
      scelta e riceve la chiave selezionata
- [ ] Annullare restituisce al comando l'assenza di scelta, e il comando può
      distinguerla da una scelta fatta
- [ ] Il titolo passato dal comando compare nelle istruzioni dell'elenco
- [ ] Frecce, filtro da tastiera, invio ed escape si comportano come oggi
- [ ] Mentre l'elenco è aperto il composer non prende testo
- [ ] Mentre l'elenco è aperto la Conversation TUI continua a ridisegnarsi
- [ ] Il comando che elenca le Session continua a funzionare come prima
- [ ] Il Picker non conosce più le Session
