# 05: Legare il lifecycle alla Session principale

**What to build:** fare in modo che Subagent, code ed esecuzioni appartengano
soltanto alla Session principale che li ha creati. Cambiare Session o chiudere
la TUI deve cancellare e dimenticare il lavoro figlio, impedendo a Reply tardive
di raggiungere un'altra conversazione.

**Blocked by:** 03: Continuare e interrogare un Subagent; 04: Eseguire più
Subagent con concorrenza limitata.

**Status:** ready-for-agent

- [ ] Il `ConversationPort` è legato alla Session principale corrente ed
      espone una cancellazione osservabile dal modulo `Subagents`.
- [ ] Quando un Command sostituisce la History corrente, il runtime chiude il
      vecchio port senza richiedere al Command di conoscere i Subagent.
- [ ] La chiusura del port cancella le esecuzioni figlie, svuota le loro code e
      invalida tutti gli ID appartenenti alla Session precedente.
- [ ] La chiusura della TUI applica lo stesso cleanup.
- [ ] La prima chiamata a un tool Subagent nella nuova Session collega il
      modulo al nuovo port e parte da una registry vuota.
- [ ] Una Reply che arriva su un port chiuso o superato viene rifiutata e non
      avvia un Turn nella Session corrente.
- [ ] La cancellazione viene propagata alle attese e alle esecuzioni
      cooperative di `amphp/parallel`.
- [ ] Eccezioni di trasporto o chiusura del Channel non vengono mostrate con i
      nomi delle classi Amp, ma tradotte in un esito leggibile.
- [ ] Un fallimento terminale porta il Subagent a `failed`, libera capacità,
      svuota i messaggi pendenti e informa l'Agent in charge.
- [ ] Clear e Resume restano normali Command dell'Host Application e non
      acquisiscono dipendenze dal toolkit.
- [ ] I test end-to-end coprono cambio di Session, shutdown, Reply tardiva,
      cancellazione durante lavoro concorrente e assenza di contaminazione fra
      Histories.
