# 01 — L'input produce nome e argomenti

**What to build:** Chi digita uno Slash command seguito da qualcosa non viene
più respinto. Oggi un comando è riconosciuto solo se coincide con l'intera
riga, quindi `/exit now` finisce fra i nomi sconosciuti invece di uscire. Dopo
questo ticket ciò che comincia con `/` produce sempre due cose — il nome, che
è la prima parola, e gli argomenti, che sono tutto il resto ripulito dagli
spazi ai bordi — e la Conversation TUI porta a termine il comando che quel
nome indica, ignorando per ora gli argomenti.

È prefactoring: i comandi restano i tre di oggi e nessun'altra cosa cambia per
chi guarda lo schermo. Serve a rendere facile il ticket successivo, dove il
nome smetterà di essere confrontato con un insieme fisso e diventerà una
ricerca in un registro.

Un messaggio per l'Agent continua a partire intatto, slash iniziale e
spaziatura compresi: la regola vale solo per ciò che la Conversation TUI
riconosce come Slash command.

**Blocked by:** None — can start immediately.

**Status:** ready-for-agent

- [ ] `/exit now` esce, e lo stesso vale per gli altri due comandi seguiti da
      testo
- [ ] `/exit` con spazi in fondo continua a uscire
- [ ] Un nome che nessun comando risponde viene ancora segnalato come
      sconosciuto e non raggiunge l'Agent
- [ ] Un messaggio che comincia per slash ma non è un comando arriva all'Agent
      esattamente come è stato scritto
- [ ] Il modulo che interpreta l'input restituisce nome e argomenti separati,
      e le sue prove lo verificano al proprio seam
- [ ] Nessun cambiamento visibile oltre a quelli sopra
