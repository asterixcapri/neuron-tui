# 07 — Alcuni comandi girano mentre l'Agent lavora

**What to build:** Uscire e chiedere aiuto funzionano anche mentre l'Agent sta
rispondendo; tutto il resto continua a essere rifiutato con l'invito a
riprovare a Turn finito, perché un comando che cambiasse conversazione a
risposta in corso la farebbe atterrare dove non appartiene.

La distinzione smette di essere un caso particolare dentro la Conversation TUI
e diventa una proprietà del comando: chi può girare durante un Turn lo
dichiara, e in cambio riceve **meno Controls**. Da lì non si apre un Picker —
nessuno deve scegliere da un elenco mentre sotto scorrono risposte e chiamate
a tool — e non si tocca l'Agent, che sta rispondendo. Restano il dire, l'
avvertire, l'elencare i comandi e l'uscire.

Il divieto è nei tipi, non nella documentazione: un comando di quel genere non
deve poter nemmeno scrivere l'apertura di un Picker. I due comandi forniti che
passano di là sono quello che esce e quello che elenca.

**Blocked by:** 05 — La Conversation TUI non monta più niente da sé; 06 —
`/help`.

**Status:** resolved

- [x] Un comando che dichiara di poter girare durante un Turn viene eseguito
      mentre l'Agent risponde
- [x] Un comando che non lo dichiara viene rifiutato durante un Turn, con
      l'invito a riprovare a Turn finito, e non viene eseguito
- [x] I comandi che girano durante un Turn ricevono soltanto il dire,
      l'avvertire, l'elencare e l'uscire
- [x] Aprire un Picker da un comando di quel genere non è esprimibile
- [x] Uscire e chiedere aiuto funzionano sia a Turn fermo sia a Turn in corso
- [x] La risposta in corso non viene disturbata da un comando eseguito nel
      frattempo

## Comments

Implementato su `ticket/07-comandi-durante-un-turno`.

- `NeuronCli\Conversation\RunsWhileWorking` è la seconda interfaccia di
  comando: estende `Command` come `SlashCommand`, e il suo `run()` prende i
  `NeuronCli\Conversation\LimitedControls` — `say`, `warn`, `commands`,
  `stop`. Niente `choose()`, niente `agent()`, `ask()` né `useAgent()`: aprire
  un Picker da un comando di quel genere non è esprimibile, perché il verbo non
  esiste sul tipo che riceve.
- La Conversation TUI decide il rifiuto mid-turn dal tipo: l'accoppiamento
  temporaneo `instanceof Leave` è sparito, con i tre punti che lo dichiaravano
  provvisorio — commento nel sorgente, README e ADR 0002.
- `Leave` e `Help` sono i due comandi forniti che girano durante un Turn.
- I due `LimitedControls` e i `Controls` restano classi distinte: quattro verbi
  ripetuti valgono meno di una gerarchia o di un delegante fra i due.
- Provato dall'alto, con terminale virtuale: un comando che dichiara di girare
  durante un Turn viene eseguito mentre l'Agent risponde e la risposta arriva
  lo stesso, con la riga del comando ancora sotto; `/help` e `/exit` rispondono
  a Turn in corso; un kit può portare un comando di quel genere. Il rifiuto di
  chi non lo dichiara era già provato e resta verde.
- README, CONTEXT.md, ADR 0002 e l'elenco dei moduli pubblici di PHPStan
  aggiornati; `examples/` non nominava nessuno dei due tipi e non è cambiato.
- Verifica: `composer test` 133 test / 460 asserzioni verdi, `composer stan`
  senza errori su entrambe le configurazioni.
