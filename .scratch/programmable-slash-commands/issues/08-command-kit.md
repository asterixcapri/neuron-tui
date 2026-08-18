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

**Status:** resolved

- [x] Montare un kit monta tutti i suoi comandi
- [x] Un kit e un comando singolo si montano nello stesso elenco
- [x] Si può montare un kit escludendo alcuni dei suoi comandi
- [x] Si può montare un kit tenendo solo alcuni dei suoi comandi
- [x] Il kit fornito per le Session riceve il Session provider una volta sola e
      lo passa ai propri comandi
- [x] Un comando di un kit che rivendica un nome già montato ferma la
      costruzione, come un comando singolo

## Comments

Fatto in `ticket/08-command-kit`, commit su `NeuronCli::mount()`, tre classi
nuove — `CommandKit`, `AbstractCommandKit`, `Commands\SessionKit` — e la
sezione «Command kits» del README.

Verificato dall'alto, con il terminale virtuale, in `tests/NeuronCliTest.php`:

- `testMountingAKitMountsEveryCommandItOffers`: montato `new SessionKit($sessions)`,
  `/sessions` riapre la Session precedente e `/clear` ne apre una nuova
  lasciando l'altra archiviata — le due hanno raggiunto lo stesso provider,
  nominato una volta sola.
- `testAKitCanBeMountedWithSomeOfItsCommandsLeftOut`: con
  `->exclude([Clear::class])`, `/clear` è sconosciuto e `/sessions` risponde.
- `testAKitCanBeMountedKeepingOnlySomeOfItsCommands`: con
  `->only([Clear::class])`, `/sessions` è sconosciuto e `/clear` risponde.
- `testACommandFromAKitClaimingATakenNameStopsTheBuild`: `Clear` montato
  accanto al kit ferma la costruzione con «Two Slash commands answer to
  /clear.», come due comandi singoli.

Un kit e un comando singolo si montano nello stesso elenco: il kit viene
srotolato in `NeuronCli::unroll()` prima dell'indicizzazione, e da lì in poi
niente sa che un kit sia mai esistito — è quello che rende identico l'errore
sul nome doppio. `exclude()` e `only()` rispondono con un altro kit e lasciano
intatto quello a cui è stato chiesto.

Verifica: `composer test` 128 test, 440 asserzioni, verdi; `composer stan`
(due configurazioni) senza errori.
