# 01 — L'input produce nome e argomenti

**What to build:** Chi digita uno Slash command seguito da qualcosa non viene
più respinto. Oggi un comando è riconosciuto solo se coincide con l'intera
riga, quindi `/exit now` finisce fra i nomi sconosciuti invece di uscire. Dopo
questo ticket ciò che comincia con `/` produce sempre due cose — il nome, che
è la prima parola, e gli argomenti, che sono tutto il resto ripulito dagli
spazi ai bordi — e la Conversation TUI porta a termine il comando che quel
nome indica, ignorando per ora gli argomenti.

È prefactoring: i comandi restano i tre di oggi e nessun'altra cosa cambia per
chi guarda lo schermo. Serve a rendere facile il ticket successivo, dove il
nome smetterà di essere confrontato con un insieme fisso e diventerà una
ricerca in un registro.

Un messaggio per l'Agent continua a partire intatto, slash iniziale e
spaziatura compresi: la regola vale solo per ciò che la Conversation TUI
riconosce come Slash command.

**Blocked by:** None — can start immediately.

**Status:** resolved

- [x] `/exit now` esce, e lo stesso vale per gli altri due comandi seguiti da
      testo
- [x] `/exit` con spazi in fondo continua a uscire
- [x] Un nome che nessun comando risponde viene ancora segnalato come
      sconosciuto e non raggiunge l'Agent
- [x] Un messaggio che comincia per slash ma non è un comando arriva all'Agent
      esattamente come è stato scritto — letto come dice la spec: ciò che
      comincia per `/` è sempre un nome, e resta locale anche quando nessun
      comando risponde; a partire intatto, spaziatura e slash compresi, è il
      messaggio per l'Agent
- [x] Il modulo che interpreta l'input restituisce nome e argomenti separati,
      e le sue prove lo verificano al proprio seam
- [x] Nessun cambiamento visibile oltre a quelli sopra

## Esito

`Submission::interpret()` restituisce `SlashCommandInput` (nome + argomenti)
oppure `MessageForAgent`, e non conosce più i nomi esistenti:
`UnknownSlashCommand` è sparito e la ricerca del comando — per ora
`SlashCommand::tryFrom()` sull'enum dei tre — è salita in
`NeuronCli::carryOut()`, dove il prossimo ticket metterà il registro. Gli
argomenti arrivano fino a lì e sono ignorati, come chiesto.

Nome e argomenti sono separati dalla stessa lista di spazi bianchi, così che
il carattere che chiude il nome non possa restare il primo degli argomenti.

Prove: `tests/Conversation/SubmissionTest.php` al seam del modulo (nome,
argomenti, `/exit now`, `/exit ` con lo spazio in fondo, messaggio intatto);
`NeuronCliTest::testACommandFollowedByArgumentsIsStillThatCommand()` dal
terminale virtuale, dove `/clear now`, `/sessions now` e `/exit now` fanno
ciascuno la propria cosa.

Verifica: `composer test` 104 test, 343 asserzioni, verde; `composer stan`
(due configurazioni) senza errori.
