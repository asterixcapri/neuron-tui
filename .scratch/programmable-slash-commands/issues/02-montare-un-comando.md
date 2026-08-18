# 02 — La Host Application può montare un suo comando

**What to build:** Una Host Application può dire alla Conversation TUI cosa si
può digitare. Costruendo la TUI passa i propri Slash command; quando qualcuno
ne digita il nome, il comando viene eseguito e riceve gli argomenti che lo
seguivano.

Un comando dichiara il nome per cui risponde, slash incluso, e una riga che lo
descrive. Mentre gira riceve i **Controls**: può dire qualcosa nella
conversazione, avvertire che qualcosa non è andato, mandare un prompt
all'Agent — che parte come se l'avesse scritto la persona, e la cui risposta
compare sullo schermo, non torna al comando — raggiungere l'Agent per
cambiargli modello, istruzioni o tool con l'API di Neuron AI, e far uscire dal
terminale. I widget della Conversation TUI restano irraggiungibili da qui.

I tre comandi che la TUI ha oggi restano dove sono e continuano a funzionare
come prima: questo ticket aggiunge la possibilità di montarne altri, non
toglie niente.

Da qui in avanti la Conversation TUI esegue codice che non è suo, quindi
comprende la protezione: quello che un comando lascia risalire diventa una
riga di errore nella conversazione e il terminale resta vivo, come già accade
per un'eccezione durante un Turn.

Due comandi che rispondono allo stesso nome fermano la costruzione della TUI
con un errore, invece di lasciar vincere silenziosamente uno dei due.

**Blocked by:** 01 — L'input produce nome e argomenti.

**Status:** ready-for-agent

- [ ] Un comando montato dalla Host Application viene eseguito quando se ne
      digita il nome
- [ ] Il comando riceve gli argomenti scritti dopo il nome
- [ ] Ciò che un comando dice, e ciò di cui avverte, compare nella
      conversazione
- [ ] Il prompt che un comando manda all'Agent produce una risposta sullo
      schermo
- [ ] Un comando può raggiungere l'Agent e cambiargli provider, istruzioni o
      tool
- [ ] Un comando può far uscire dal terminale
- [ ] Un comando che va in errore lascia una riga di errore e il terminale
      resta utilizzabile
- [ ] Due comandi con lo stesso nome fermano la costruzione della Conversation
      TUI
- [ ] I tre comandi esistenti si comportano esattamente come prima
