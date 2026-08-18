# 06 — `/help`

**What to build:** Chi apre il terminale può scoprire cosa ci si digita.
Montando il comando fornito, digitare `/help` elenca tutti gli Slash command
montati con la riga che ciascuno usa per descriversi, sé stesso compreso.

Perché sia possibile senza scaricare sulla Host Application un problema di
ordine di costruzione — la lista contiene anche il comando che deve
riceverla — è la Conversation TUI a passarla: fra i Controls compare il verbo
che elenca i comandi montati. La Host Application monta il comando senza
argomenti.

Lo stesso verbo servirà, più avanti, a completare il nome mentre si digita:
questo ticket non lo fa.

**Blocked by:** 05 — La Conversation TUI non monta più niente da sé.

**Status:** ready-for-agent

- [ ] Un comando può ottenere l'elenco dei comandi montati, ciascuno con nome
      e descrizione
- [ ] Digitando `/help` compaiono tutti i comandi montati, sé stesso compreso
- [ ] I comandi montati dalla Host Application compaiono accanto a quelli
      forniti
- [ ] Il comando si monta senza ricevere niente alla costruzione
- [ ] Una Conversation TUI su cui `/help` non è montato non lo riconosce
