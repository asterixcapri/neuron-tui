# 01: Consegnare una Reply ritardata alla conversazione

**What to build:** permettere a un tool che ha già terminato di consegnare in
seguito una Reply tipizzata alla conversazione principale. La Reply deve
entrare nello stesso ordinamento degli input della persona, senza essere
mostrata come testo scritto dalla persona, e deve avviare un nuovo Turn quando
l'Agent in charge è disponibile.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] La coda dei Turn conserva input tipizzati e mantiene un solo ordinamento
      FIFO fra messaggi della persona e Reply dei Subagent.
- [ ] Una Reply conserva almeno l'ID del Subagent e il testo completo da
      consegnare all'Agent in charge.
- [ ] Una Reply accettata mentre l'Agent in charge è libero avvia un nuovo Turn.
- [ ] Una Reply accettata mentre un Turn è attivo aspetta dietro gli input già
      accettati.
- [ ] La Reply viene consegnata al modello con provenienza e ID riconoscibili,
      ma non viene dipinta né riletta come testo scritto dalla persona.
- [ ] Un tool capace di rispondere successivamente riceve il
      `ConversationPort` corrente dopo il proprio evento di chiamata e prima
      dell'esecuzione.
- [ ] Il tool restituisce normalmente una sola volta; la Reply successiva è un
      nuovo input della conversazione, non un secondo risultato del tool.
- [ ] L'attesa di una Reply non aggiunge ricezioni bloccanti al tick della TUI.
- [ ] I test coprono consegna immediata, accodamento durante un Turn,
      ordinamento misto e attribuzione visiva usando provider e terminale finti.

