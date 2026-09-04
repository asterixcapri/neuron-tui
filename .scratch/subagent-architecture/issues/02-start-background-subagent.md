# 02: Avviare un Subagent in background

**What to build:** consentire all'Host Application di montare il toolkit e
all'Agent in charge di usare `subagent` per creare un Subagent reale. Il tool
deve restituire subito un ID mentre un worker separato esegue il primo Turn e,
al completamento, consegna automaticamente la Reply alla conversazione
principale.

**Blocked by:** 01: Consegnare una Reply ritardata alla conversazione.

**Status:** ready-for-agent

- [ ] L'Host Application può montare un normale `SubagentToolkit` configurato
      con una classe autoloadabile di Agent.
- [ ] Il tool `subagent` accetta un task, crea un ID opaco e restituisce ID e
      stato senza attendere la risposta del figlio.
- [ ] La classe del Subagent viene costruita nel worker attraverso la normale
      convenzione `::make()` senza argomenti.
- [ ] Ogni Turn figlio viene eseguito tramite `amphp/parallel`, aggiunto come
      dipendenza runtime del package.
- [ ] Questo primo percorso esegue un solo Turn figlio alla volta e mantiene in
      stato `queued` eventuale altro lavoro.
- [ ] La TUI continua a dipingere e leggere input mentre il Turn figlio è in
      esecuzione.
- [ ] Risposta e History completata tornano al processo principale e producono
      automaticamente una `SubagentReply`.
- [ ] La History attraversa il processo usando la rappresentazione
      JSON-compatible dei messaggi, senza serializzare Agent, provider, History
      vive o callable dei tool.
- [ ] Il worker usa l'autoloader dell'Host Application e non presume il layout
      del repository della libreria.
- [ ] Stdout e stderr del worker non vengono collegati direttamente al
      terminale della conversazione.
- [ ] Un errore del provider o del worker non abbatte la TUI e diventa un
      fallimento leggibile associato al Subagent.
- [ ] I test usano un Agent e un provider finti autoloadabili, includono una
      History con un tool basato su closure e non richiedono rete o chiavi API.

