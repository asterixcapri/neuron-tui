# 02: Il Picker mostra opzioni come blocchi completi

**What to build:** Una persona legge ogni opzione come un blocco distinto,
formato dalla label e da un detail opzionale sotto di essa. Il blocco conserva
allineamento, gerarchia visiva e integrità mentre viene selezionato, mandato a
capo o fatto scorrere.

**Blocked by:** 01/Ogni Picker usa `ChoiceOption`.

**Status:** ready-for-agent

- [ ] `ChoiceOption` può portare un detail opzionale senza introdurre un
      secondo tipo di scelta o un secondo metodo `choose()`.
- [ ] Un detail assente non lascia una riga interna vuota; un detail presente
      ma vuoto produce `InvalidArgumentException` prima di aprire il Picker.
- [ ] Label e detail cominciano nella stessa colonna, mentre la freccia di
      selezione occupa una colonna separata alla loro sinistra.
- [ ] La label selezionata usa il colore di accento e il detail mantiene il
      colore secondario più chiaro anche quando la sua opzione è selezionata.
- [ ] Una riga vuota separa due opzioni adiacenti e non è richiesta dopo
      l'ultima opzione visibile.
- [ ] Le interruzioni di riga fornite in label e detail vengono normalizzate
      prima del wrapping terminale.
- [ ] Label e detail vanno a capo indipendentemente fino a due righe visive,
      con continuazioni allineate ed ellissi oltre il limite.
- [ ] Il Picker mostra e fa scorrere blocchi completi senza separare una label
      dal suo detail.
- [ ] Il rendering e la navigazione dei blocchi sono verificati dal seam Slash
      command, `Controls::choose()` e `VirtualTerminal`, senza test dipendenti
      dall'albero dei widget interni.
