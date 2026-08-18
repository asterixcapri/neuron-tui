# 05 — La Conversation TUI non monta più niente da sé

**What to build:** La Conversation TUI parte senza Slash command. Chi la
costruisce senza nominarne nessuno ottiene un terminale in cui si chatta e si
esce con `Ctrl+C`; chi vuole `/clear`, `/sessions` o `/exit` li monta come
monterebbe i propri.

I tre comandi restano forniti dalla libreria, ora come classi montabili, e
ciascuno accetta il proprio nome alla costruzione: una Host Application che
preferisce `/quit` a `/exit` non deve riscrivere niente. Quelli che toccano le
Session ricevono il **Session provider** alla costruzione, perché il posto
dove vivono le conversazioni si nomina dove serve: sparisce dagli argomenti
della Conversation TUI, che smette di conoscerlo. Aprire una conversazione
diventa installare una History sull'Agent, e non ha un verbo suo fra i
Controls.

Perché questo regga, dopo ogni comando la Conversation TUI ridisegna la
conversazione leggendola dall'Agent. Così qualunque cosa il comando abbia
fatto — cambiato conversazione, cambiato Agent — lo schermo dice la verità
senza che il comando debba dichiararlo.

È la rottura di interfaccia della libreria, e niente è mai stato rilasciato,
quindi non costa a nessuno. Comprende l'ADR che registra l'inversione della
decisione scritta nel sorgente — che un insieme fisso di Slash command non
giustifica un registro — accanto a quello sulle Session, e l'aggiornamento del
README, dove va detto anche che senza il comando di uscita si esce solo con
`Ctrl+C`.

**Blocked by:** 02 — La Host Application può montare un suo comando; 03 — Il
Picker serve a scegliere qualsiasi cosa.

**Status:** ready-for-agent

- [ ] Una Conversation TUI costruita senza nominare comandi non ne riconosce
      nessuno, e ogni nome digitato risulta sconosciuto
- [ ] I tre comandi forniti si montano come si monta un comando della Host
      Application, e si comportano come prima
- [ ] Ciascuno accetta un nome proprio alla costruzione, con il suo default
- [ ] Il Session provider è un argomento dei comandi che lo usano, non della
      Conversation TUI
- [ ] Dopo un comando che installa un'altra History, lo schermo mostra quella
- [ ] Dopo un comando che non tocca la conversazione, lo schermo non cambia
- [ ] Il README descrive il montaggio dei comandi e dice che senza il comando
      di uscita si esce con `Ctrl+C`
- [ ] Un ADR registra il registro dei comandi e l'uscita del Session provider
      dalla costruzione della Conversation TUI
