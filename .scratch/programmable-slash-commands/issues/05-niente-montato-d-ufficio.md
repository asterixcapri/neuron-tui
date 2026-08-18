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

**Status:** resolved

- [x] Una Conversation TUI costruita senza nominare comandi non ne riconosce
      nessuno, e ogni nome digitato risulta sconosciuto
- [x] I tre comandi forniti si montano come si monta un comando della Host
      Application, e si comportano come prima
- [x] Ciascuno accetta un nome proprio alla costruzione, con il suo default
- [x] Il Session provider è un argomento dei comandi che lo usano, non della
      Conversation TUI
- [x] Dopo un comando che installa un'altra History, lo schermo mostra quella
- [x] Dopo un comando che non tocca la conversazione, lo schermo non cambia
- [x] Il README descrive il montaggio dei comandi e dice che senza il comando
      di uscita si esce con `Ctrl+C`
- [x] Un ADR registra il registro dei comandi e l'uscita del Session provider
      dalla costruzione della Conversation TUI

## Answer

Fatto. La Conversation TUI non monta più niente da sé: `commands` è l'unico
posto da cui un nome dopo lo slash può arrivare, e il registro non ha più nomi
riservati. `BuiltInCommand` è sparito; al suo posto ci sono
`NeuronCli\Conversation\Commands\{Clear,Sessions,Leave}`, ciascuno con il
proprio nome alla costruzione (`/clear`, `/sessions`, `/exit` di default) e con
il Session provider come primo argomento per i due che lo usano. Il parametro
`sessionProvider` è uscito dal costruttore di `NeuronCli`.

Dopo ogni comando la TUI rilegge la History dall'Agent e ridisegna solo se non
è più quella che il comando aveva ricevuto: così una conversazione installata
compare senza che il comando lo dica, mentre `say()`, `warn()` e `ask()` di un
comando che non tocca la conversazione restano dove sono stati scritti. La
riconciliazione precede l'eventuale riga di errore, così un comando che fallisce
dopo aver cambiato conversazione lascia l'errore sulla conversazione giusta.

Uscire resta l'unico comando non rifiutato durante un Turn: per ora la TUI lo
riconosce come `instanceof Leave`, in attesa della separazione di tipo del
ticket 07.

Fuori dai criteri, ma conseguenza diretta: la riga di stato diceva
`/exit exits`, che è una bugia quando nessuno monta `Leave`, e ora dice
`Ctrl+C exits`.

Documentazione: README riscritto (montaggio, tabella dei tre comandi forniti,
nomi personalizzati, Session provider sui comandi, `Ctrl+C` senza `Leave`) e
`docs/adr/0002-the-conversation-tui-mounts-nothing-on-its-own.md` accanto a
quello sulle Session. `examples/demo.php` e `PublicModulePolicy` aggiornati.

Nota di revisione: la riconciliazione confronta la History che il comando ha
ricevuto con quella che l'Agent tiene dopo, quindi un comando che scrive nella
stessa History non fa ridisegnare niente — è la lettura che rende veri insieme
il quinto e il sesto criterio, ed è scritta nell'ADR. Anche `CONTEXT.md` è stato
corretto: non esiste più un Session provider di default.

Verifica: `composer test` 124 test / 417 asserzioni verdi, `composer stan`
senza errori su entrambe le configurazioni.
