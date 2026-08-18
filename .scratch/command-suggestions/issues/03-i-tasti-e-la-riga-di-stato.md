# 03 — I tasti e la riga di stato

**What to build:** Si sceglie una riga e la si fa scrivere al posto proprio,
senza che nessun tasto cambi il significato che aveva prima.

↑↓ muovono la riga scelta mentre la lista è aperta — cosa che al composer non
toglie niente, perché lì la bozza è per definizione una riga sola — e la scelta
torna in cima ogni volta che l'insieme delle righe mostrate cambia: chi digita
sta restringendo, non scorrendo. Tab scrive nel composer il nome scelto seguito
da uno spazio, il che chiude la lista da sé e lascia il cursore dove si
scrivono gli argomenti. Dove non c'è niente da completare — la riga «No
commands match», o la lista chiusa — Tab non fa niente, e in particolare non
infila mai una tabulazione nella bozza.

Esc chiude la lista lasciando intatto quello che si stava scrivendo; una
seconda Esc svuota la bozza, come ha sempre fatto. **Invio non viene mai
intercettato:** manda quello che è scritto, lista aperta o chiusa che sia, e
chi ha scritto il nome per intero non deve batterlo due volte.

Mentre la lista è aperta, la riga di stato in fondo nomina i tasti che hanno
senso adesso — muoversi, completare, mandare — e torna a quella di prima appena
la lista si chiude. È il mestiere che quella riga svolge già negli altri stati.

**Blocked by:** 01 — La lista compare mentre si scrive un nome; 02 — Il filtro,
l'ordine e il grassetto.

**Status:** ready-for-agent

- [ ] ↑↓ muovono la riga scelta senza spostare il cursore nel composer
- [ ] La scelta torna in cima ogni volta che l'insieme delle righe mostrate
      cambia
- [ ] Tab scrive nel composer il nome scelto seguito da uno spazio
- [ ] Dopo il completamento la lista non è più sullo schermo e si possono
      scrivere gli argomenti
- [ ] Tab non fa niente quando nessun comando corrisponde, e non inserisce mai
      una tabulazione
- [ ] Tab non fa niente quando la lista è chiusa
- [ ] Esc chiude la lista lasciando la bozza; una seconda Esc la svuota
- [ ] Invio manda quello che è scritto anche a lista aperta, e il comando parte
- [ ] La riga di stato nomina i tasti mentre la lista è aperta, e torna com'era
      dopo
