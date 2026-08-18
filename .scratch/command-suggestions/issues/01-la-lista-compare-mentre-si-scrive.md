# 01 — La lista compare mentre si scrive un nome

**What to build:** Chi scrive un nome dopo lo slash vede i comandi che potrebbe
scrivere. Appena il composer contiene una riga sola che comincia con `/`,
sopra la riga del composer compare una fascia con i comandi montati: per
ciascuno il nome e la riga che usa per descriversi. La fascia se ne va da sé
quando quello che si sta scrivendo non è più un nome — uno spazio, un a capo,
lo slash cancellato — e non compare affatto per uno slash in mezzo a un
messaggio, che resta testo per l'Agent.

Sono le Command suggestions di `CONTEXT.md`, e non sono il Picker: nessuno
viene sospeso, il focus e i tasti restano al composer, la bozza resta dov'era.
Il widget di lista sottostante è lo stesso, il resto no.

Questo ticket non filtra niente: la fascia mostra tutti i comandi montati,
nell'ordine in cui la Host Application li ha nominati. Se non ce n'è nessuno,
mostra la riga che dice che non corrisponde niente — che è anche quello che
vedrà chi ha montato una TUI senza comandi.

**Blocked by:** None — can start immediately.

**Status:** ready-for-agent

- [ ] Digitando `/` compaiono tutti i comandi montati, ciascuno con la sua
      descrizione
- [ ] La fascia sta sopra la riga del composer, e il composer continua a
      prendere il testo che si scrive
- [ ] Sono visibili otto righe, con l'indicatore di scorrimento quando i
      comandi non ci stanno
- [ ] Uno spazio, un a capo o lo slash cancellato fanno sparire la fascia
- [ ] Uno slash in mezzo a un messaggio non fa comparire niente
- [ ] Su una TUI senza comandi montati compare la riga `No commands match "…"`
      invece della lista
- [ ] Invio continua a mandare quello che è scritto, con la fascia aperta
