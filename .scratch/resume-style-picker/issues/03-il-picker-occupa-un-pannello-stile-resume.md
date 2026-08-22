# 03: Il Picker occupa un pannello stile `/resume`

**What to build:** Quando una persona deve scegliere, la Conversation TUI
sostituisce temporaneamente composer e status con un pannello inferiore
modellato su Claude Code `/resume`, lasciando la History visibile e rendendo
inequivocabile il passaggio dalla scrittura alla scelta.

**Blocked by:** 02/Il Picker mostra opzioni come blocchi completi.

**Status:** ready-for-agent

- [ ] Il pannello mostra separatore superiore, titolo con posizione e totale,
      lista delle opzioni e istruzioni persistenti in fondo.
- [ ] `choose()` accetta una descrizione opzionale del pannello senza creare
      una variante simple/advanced o un'operazione generica `present()`.
- [ ] Una descrizione assente non occupa spazio; una descrizione presente va a
      capo fino a tre righe visive e termina con un'ellissi oltre il limite.
- [ ] Il contatore iniziale mostra la prima opzione e il totale fornito, e si
      aggiorna quando la selezione cambia.
- [ ] Il pannello sostituisce completamente composer e status durante la
      scelta, mentre la History resta visibile.
- [ ] Up e Down spostano la selezione e fanno wrap agli estremi; Enter sceglie
      la key attiva ed Escape annulla.
- [ ] Le istruzioni comunicano sempre movimento, scelta e annullamento.
- [ ] Alla chiusura vengono ripristinati composer, status e focus; la chiusura
      della Conversation TUI completa l'attesa dello Slash command una sola
      volta.
- [ ] Mentre il Picker è aperto non compaiono Command suggestions.
- [ ] Il comportamento completo è verificato attraverso uno Slash command e
      `VirtualTerminal`, includendo scelta, Escape, terminal shutdown e una
      seconda apertura.
