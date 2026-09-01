# 04: Consentire nomi di comando duplicati

**What to build:** Allineare l'accumulo dei comandi alla semantica di
`Agent::addTool()`. La Host Application può aggiungere più comandi con lo
stesso nome senza impedire la costruzione della TUI; l'ordine resta visibile e
determina in modo stabile quale comando risponde.

**Blocked by:** 03/Esporre la configurazione fluente di Tui.

**Status:** ready-for-agent

- [ ] Aggiungere un comando il cui nome è già presente non produce un errore
      né durante `addCommand()` né all'avvio della Conversation TUI.
- [ ] Quando viene inviato un nome duplicato, viene eseguito il primo comando
      corrispondente nell'ordine complessivo di aggiunta.
- [ ] Tutti i comandi duplicati restano visibili nei suggerimenti nello stesso
      ordine, senza deduplicazione o riordinamento aggiuntivi.
- [ ] I duplicati provenienti da chiamate separate, dallo stesso array, da un
      Command kit o dalla combinazione di queste forme seguono la stessa
      regola.
- [ ] Help e gli altri consumatori dell'elenco montato ricevono la collezione
      ordinata completa, incluse le ripetizioni.
- [ ] La Conversation TUI continua a non montare comandi propri e nessun nome
      diventa riservato.
- [ ] I test osservano esecuzione e suggerimenti attraverso `Tui` e il Terminal
      controllabile, senza vincolare la struttura interna usata per lookup e
      ordinamento.
- [ ] Tutti gli altri comportamenti stabiliti da ADR 0002 e dalla spec restano
      invariati, e suite completa e analisi statica passano.
