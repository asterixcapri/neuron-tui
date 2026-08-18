# 08 — Command kit

**What to build:** Una Host Application può montare in una riga un gruppo di
Slash command che vanno insieme, invece di elencarli uno per uno. Un
**Command kit** offre i propri comandi e si porta dietro ciò che serve loro,
così il Session provider viene nominato una volta sola per tutti i comandi
delle Session invece che comando per comando.

Un kit si può montare per intero, escludendone qualcuno — `/sessions` senza
`/clear`, per un'applicazione in cui le conversazioni non si buttano — oppure
tenendo solo quelli nominati. Dopo il montaggio un comando arrivato da un kit
e uno montato singolarmente sono indistinguibili: stessa lista, stesse regole,
stesso errore se due rivendicano lo stesso nome.

La libreria fornisce il kit dei comandi delle Session. Il vocabolario evita di
chiamarlo toolkit, che in Neuron AI significa un gruppo di tool per il
modello.

**Blocked by:** 05 — La Conversation TUI non monta più niente da sé.

**Status:** claimed

- [ ] Montare un kit monta tutti i suoi comandi
- [ ] Un kit e un comando singolo si montano nello stesso elenco
- [ ] Si può montare un kit escludendo alcuni dei suoi comandi
- [ ] Si può montare un kit tenendo solo alcuni dei suoi comandi
- [ ] Il kit fornito per le Session riceve il Session provider una volta sola e
      lo passa ai propri comandi
- [ ] Un comando di un kit che rivendica un nome già montato ferma la
      costruzione, come un comando singolo
