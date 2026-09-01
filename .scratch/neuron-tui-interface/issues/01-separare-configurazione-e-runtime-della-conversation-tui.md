# 01: Separare configurazione e runtime della Conversation TUI

**What to build:** Riorganizzare internamente l'entry point esistente affinché
la configurazione della Conversation TUI sia separata dal runtime che gestisce
view, input, Turn e History. Per la Host Application non cambia ancora nulla:
costruzione, esecuzione e tutti i comportamenti osservabili restano quelli
attuali. Il risultato prepara un confine interno attraverso il quale il futuro
`Tui` potrà rimandare l'assemblaggio della UI fino a `run()`.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] L'entry point corrente continua ad accettare e applicare Agent, branding,
      Terminal e comandi con il contratto pubblico esistente.
- [ ] Conversazione, streaming, Turn in coda, Slash command, Command kit,
      Controls, Picker, Sessions, suggerimenti e uscita mantengono il
      comportamento osservabile corrente.
- [ ] L'assemblaggio e l'esecuzione della Conversation TUI sono racchiusi
      dietro un unico confine interno riusabile dall'entry point, senza esporre
      nuovi concetti pubblici.
- [ ] Il seam di verifica rimane l'interazione completa attraverso l'entry
      point e il Terminal controllabile; i test non dipendono dai nuovi
      dettagli interni.
- [ ] La suite completa e l'analisi statica passano senza modifiche intenzionali
      all'API pubblica.
