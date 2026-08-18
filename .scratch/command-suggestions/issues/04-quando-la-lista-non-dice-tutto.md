# 04 — Quando la lista non dice tutto

**What to build:** La lista non promette mai qualcosa che non succederà.

Mentre l'Agent lavora, la Conversation TUI respinge i comandi che non
dichiarano nel tipo di girare durante un Turn, e lo dice con una riga di
errore. Suggerire quei nomi proprio allora sarebbe una promessa falsa: finché
un Turn è in volo la lista contiene i soli comandi eseguibili in quel momento,
e se la Host Application non ne ha montato nessuno si finisce sulla riga che
dice che non corrisponde niente. Finito il Turn, la lista torna intera.

Mentre un Picker è aperto non compare invece nessuna lista: lì il composer non
prende testo, quindi non c'è nessuna bozza da completare, e le due cose non
sono mai sullo schermo insieme.

**Blocked by:** 01 — La lista compare mentre si scrive un nome.

**Status:** ready-for-agent

- [ ] Mentre l'Agent lavora, la lista contiene i soli comandi eseguibili
      durante un Turn
- [ ] Un comando che verrebbe respinto non compare nella lista mentre l'Agent
      lavora
- [ ] Se nessun comando è eseguibile durante un Turn, compare la riga
      `No commands match "/…"`
- [ ] Finito il Turn, la lista torna a contenere tutti i comandi montati
- [ ] Mentre un Picker è aperto non compare nessuna lista
