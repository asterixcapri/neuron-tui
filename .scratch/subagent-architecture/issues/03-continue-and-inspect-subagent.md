# 03: Continuare e interrogare un Subagent

**What to build:** permettere all'Agent in charge di continuare la
conversazione di un Subagent tramite `subagent_send` e di leggerne stato e
History tramite `subagent_status`. Ogni nuovo Turn deve mantenere l'identità e
il contesto completato del Subagent anche se viene eseguito da un altro worker.

**Blocked by:** 02: Avviare un Subagent in background.

**Status:** ready-for-agent

- [ ] Il toolkit espone `subagent_send` con ID del Subagent e messaggio.
- [ ] Un messaggio inviato a un Subagent `idle` avvia il suo Turn successivo.
- [ ] Un messaggio inviato mentre il Subagent è `queued` o `running` entra
      nella sua coda FIFO e non modifica l'inferenza già in corso.
- [ ] Dopo ogni Reply, la nuova History viene salvata prima di avviare il
      successivo messaggio in coda.
- [ ] Il Turn successivo ricostruisce l'Agent nel worker con la History
      completata e può essere eseguito da un worker diverso.
- [ ] Il toolkit espone `subagent_status` come sola lettura della registry, senza
      invocare un modello.
- [ ] Lo status riporta ID, stato, durata quando applicabile, numero dei
      messaggi accodati e History completata.
- [ ] La History è la sola fonte della conversazione figlia e non viene
      mantenuto un campo duplicato `last_reply`.
- [ ] Durante un Turn attivo lo status non inventa testo parziale, reasoning o
      attività non ancora presenti nella History completata.
- [ ] Un ID sconosciuto viene rifiutato chiaramente.
- [ ] Un ID in stato `failed` non accetta altri messaggi; per riprovare deve
      essere creato un nuovo Subagent.
- [ ] I test dimostrano continuazione multi-Turn, conservazione della History,
      FIFO durante un Turn attivo e lettura dello status senza provider.

