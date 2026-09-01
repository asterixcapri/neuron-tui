# 03: Esporre la configurazione fluente di Tui

**What to build:** Offrire alla Host Application l'interfaccia definitiva e
Neuron AI-native di `Tui`. Un Agent pronto all'uso è obbligatorio, il Terminal
è un seam infrastrutturale opzionale, `make()` e il costruttore sono
equivalenti, e branding e comandi vengono accumulati fluentemente prima che
`run()` costruisca e conduca una sola Session terminale.

**Blocked by:** 02/Rinominare integralmente il package in Neuron TUI.

**Status:** ready-for-agent

- [ ] Il costruttore pubblico richiede un Agent concreto e accetta soltanto un
      Terminal opzionale come secondo argomento.
- [ ] `make()` espone gli stessi argomenti tipizzati del costruttore, restituisce
      `self`, crea un'istanza equivalente ed è implementato senza dipendere dal
      trait di costruzione statica di Neuron AI.
- [ ] `setTitle()` e `setSubtitle()` restituiscono la stessa istanza; i default
      sono `Neuron AI` e `Agent conversation`, e ogni stringa fornita, inclusa
      quella vuota, viene conservata senza policy aggiuntiva.
- [ ] `addCommand()` accetta un Command, un Command kit oppure un array di
      entrambi, valida ogni elemento al momento dell'aggiunta, accumula chiamate
      successive e conserva l'ordine di inserimento.
- [ ] Un Command kit contribuisce i propri comandi nel punto in cui viene
      aggiunto e continua a rispettare le proprie selezioni `only()` ed
      `exclude()`.
- [ ] Nessun Slash command viene montato automaticamente; una TUI minima resta
      utilizzabile e può essere lasciata con `Ctrl+C`.
- [ ] Costruzione e mutatori conservano soltanto dipendenze e configurazione;
      view, listener, lookup dei comandi, suggerimenti, Turn e proiezione
      iniziale della History vengono risolti quando inizia `run()`.
- [ ] Tutti i mutatori diventano errori logici dal momento in cui `run()`
      comincia, e una stessa istanza non può essere eseguita una seconda volta.
- [ ] `run()` resta bloccante, restituisce `void`, conserva i controlli del TTY
      e lascia la History sotto la proprietà dell'Agent.
- [ ] Un comando montato può ancora mettere un altro Agent in carica tramite
      Controls, conservando il comportamento stabilito della History.
- [ ] `Tui` non espone configurazione di provider, istruzioni, tool,
      middleware, persistence, History o Sessions e non accetta Workflow
      arbitrari.
- [ ] La documentazione introduttiva usa `make()` con il solo Agent e mostra la
      configurazione fluente senza presentare il Terminal come dipendenza
      ordinaria.
- [ ] I test verificano costruzione, branding, comandi e lifecycle attraverso
      l'API pubblica e il Terminal controllabile, senza osservare lo stato
      interno del bootstrap.
- [ ] La suite completa e l'analisi statica passano con la nuova interfaccia.
