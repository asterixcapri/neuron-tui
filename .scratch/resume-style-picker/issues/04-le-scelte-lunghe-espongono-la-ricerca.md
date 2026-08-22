# 04: Le scelte lunghe espongono la ricerca

**What to build:** Una persona può restringere una scelta lunga attraverso un
campo di ricerca visibile, mentre una scelta corta resta compatta. Risultati,
contatore, selezione e stato vuoto descrivono sempre la query corrente.

**Blocked by:** 03/Il Picker occupa un pannello stile `/resume`.

**Status:** ready-for-agent

- [ ] Cinque opzioni non mostrano la ricerca e non accettano testo di filtro;
      sei opzioni la mostrano fin dall'apertura.
- [ ] Una ricerca mostrata resta visibile anche quando la query riduce i
      risultati sotto la soglia di sei.
- [ ] Digitare modifica il campo di ricerca e non il composer; Backspace
      modifica la query mentre Up, Down, Enter ed Escape continuano a governare
      la lista.
- [ ] Il filtro cerca sottostringhe contigue senza distinzione fra maiuscole e
      minuscole nel testo completo e non abbreviato di label e detail.
- [ ] Le corrispondenze mantengono l'ordine fornito dallo Slash command e non
      ricevono ranking fuzzy.
- [ ] Ogni variazione della query porta la selezione sulla prima
      corrispondenza.
- [ ] Il contatore usa posizione e numero dei risultati filtrati, non il
      totale originario nascosto dalla query.
- [ ] Senza corrispondenze il pannello mostra
      `No options match "<query>"`, il contatore legge `0 of 0` ed Enter non ha
      effetto; Backspace ed Escape restano attivi.
- [ ] Ogni nuova apertura parte con query vuota e prima opzione selezionata.
- [ ] Soglia, filtro su label, filtro su detail, ordine, stato vuoto e
      ripristino dei risultati sono verificati attraverso lo Slash command e
      `VirtualTerminal`.
