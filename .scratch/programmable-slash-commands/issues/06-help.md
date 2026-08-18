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

**Status:** resolved

- [x] Un comando può ottenere l'elenco dei comandi montati, ciascuno con nome
      e descrizione
- [x] Digitando `/help` compaiono tutti i comandi montati, sé stesso compreso
- [x] I comandi montati dalla Host Application compaiono accanto a quelli
      forniti
- [x] Il comando si monta senza ricevere niente alla costruzione
- [x] Una Conversation TUI su cui `/help` non è montato non lo riconosce

## Comments

Implementato su `ticket/06-help`.

- `Controls::commands()` è il verbo nuovo: torna i comandi montati, nell'ordine
  in cui la Host Application li ha nominati, presi dal registro della
  Conversation TUI. Il comando che legge la lista ci compare dentro.
- `NeuronCli\Conversation\Commands\Help` è il comando fornito: si costruisce
  con il solo nome (`/help` di default) e dice una riga per comando,
  `nome — descrizione`.
- Provato dall'alto, con terminale virtuale: `/help` elenca sé stesso, gli
  altri comandi forniti e quello scritto dalla Host Application; una TUI senza
  `Help` montato risponde «Unknown Slash command: /help».
- README, CONTEXT.md, l'esempio di riferimento e l'elenco dei moduli pubblici
  di PHPStan aggiornati.
- Verifica: `composer test` 126 test / 424 asserzioni verdi, `composer stan`
  senza errori su entrambe le configurazioni.
