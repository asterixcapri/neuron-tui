# 01: Ogni Picker usa `ChoiceOption`

**What to build:** Uno Slash command offre una scelta attraverso un'unica
rappresentazione ordinata delle opzioni. Ogni `ChoiceOption` separa la key
restituita dalla label visibile, e `choose()` continua ad attendere una scelta
o un annullamento senza esporre dettagli della TUI al comando.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] `choose()` accetta una lista ordinata e non vuota di `ChoiceOption`, non
      una mappa di stringhe o forme alternative.
- [ ] Ogni `ChoiceOption` porta una key e una label; la key non viene mostrata
      e viene restituita senza modifiche quando l'opzione è scelta.
- [ ] Escape e la chiusura della Conversation TUI restituiscono `null` come
      normale esito di annullamento.
- [ ] Lista vuota, key duplicate e label vuote producono
      `InvalidArgumentException` prima di cambiare ciò che è sullo schermo.
- [ ] Una seconda scelta aperta contemporaneamente conserva l'attuale
      comportamento `LogicException`.
- [ ] Tutti gli Slash command forniti dalla libreria e gli esempi pubblici
      usano `ChoiceOption`; non resta un secondo formato key-to-label.
- [ ] Selezione, navigazione, annullamento, riapertura e attesa asincrona
      continuano a funzionare attraverso uno Slash command e
      `VirtualTerminal`.
- [ ] `LimitedControls` continua a non offrire `choose()`, quindi un comando
      eseguito durante un Turn non può aprire un Picker.
