# Spec: Command suggestions

Status: ready-for-agent

Vocabolario: `CONTEXT.md` (Command suggestions, Slash command, Picker,
Conversation TUI, Controls, Host Application, Turn, Command kit).
Ricerca di riferimento: `docs/research/claude-code-interface-basics.md`.
Prove raccolte su Claude Code v2.1.234 durante il grilling, riportate sotto in
Further Notes.

## Problem Statement

Chi apre il terminale non sa cosa può scriverci dentro. Gli Slash command sono
quelli che la Host Application ha montato — nessuno è montato d'ufficio, per
ADR 0002 — quindi non c'è nemmeno un insieme noto da ricordare: cambia da
applicazione ad applicazione.

Oggi l'unico modo di scoprirli è digitare `/help`, che però bisogna sapere che
esiste ed essere stati abbastanza fortunati da vederlo montato. E chi il nome
lo ricorda a metà non ha nessun aiuto: `/sess` non è `/sessions`, quindi non
succede niente finché non si indovina il nome intero, lettera per lettera. Un
errore di battitura si scopre solo dopo aver battuto Invio, sotto forma di
«Unknown Slash command».

## Solution

Mentre si scrive un nome dopo lo slash, il composer mostra i comandi montati
che gli assomigliano, ciascuno con la riga che lo descrive. La lista si
restringe a ogni tasto; ↑↓ scelgono una riga e Tab la completa nel composer.

Non è il Picker: nessuno viene sospeso, i tasti restano al composer, la bozza
resta dov'era. È una proprietà di ciò che si sta scrivendo, non uno stato in
cui la Conversation TUI entra. Invio continua a mandare quello che è scritto,
esattamente come prima che la lista esistesse.

Niente da montare e niente da configurare: la lista contiene i comandi che la
Host Application ha già montato, quindi una TUI senza comandi non mostra mai
niente e una TUI con dieci comandi li mostra tutti senza che nessuno chieda.

## User Stories

1. Come persona al terminale, voglio che digitare `/` mi mostri i comandi
   montati, così da sapere cosa posso scrivere senza conoscerli già.
2. Come persona al terminale, voglio vedere accanto a ogni nome la riga che lo
   descrive, così da scegliere quello giusto invece del primo che riconosco.
3. Come persona al terminale, voglio che la lista si restringa mentre scrivo,
   così da arrivare in fretta a quello che cerco.
4. Come persona al terminale, voglio ritrovare un comando anche ricordandone
   solo un pezzo in mezzo — `/wind` per `/rewind` — così da non dover
   indovinare da che lettera comincia.
5. Come persona al terminale, voglio vedere in grassetto le lettere che ho
   scritto dentro ogni nome, così da capire perché una riga è nella lista.
6. Come persona al terminale, voglio che il nome che ho scritto per intero sia
   la prima riga, così che la corrispondenza esatta non finisca sotto una
   somiglianza.
7. Come persona al terminale, voglio muovermi nella lista con ↑↓, così da
   scegliere una riga diversa dalla prima.
8. Come persona al terminale, voglio completare con Tab la riga scelta, così
   da non finire di scrivere il nome a mano.
9. Come persona al terminale, voglio che dopo il completamento il cursore sia
   già dove si scrivono gli argomenti, così da tirare dritto con `/model
   haiku`.
10. Come persona al terminale, voglio che Invio continui a mandare quello che
    è scritto, aperta o chiusa che sia la lista, così da non dover imparare
    un'eccezione.
11. Come persona al terminale, voglio chiudere la lista con Esc senza perdere
    quello che stavo scrivendo, così da togliermela di mezzo quando mi copre
    la conversazione.
12. Come persona al terminale, voglio che quando nessun comando corrisponde me
    lo si dica, così da correggere prima di battere Invio invece di scoprirlo
    dopo.
13. Come persona al terminale, voglio che la lista sparisca appena passo agli
    argomenti, così da avere lo schermo libero mentre scrivo il resto.
14. Come persona al terminale, voglio che scrivere un messaggio normale non
    apra mai niente, così che uno slash in mezzo a una frase resti testo.
15. Come persona al terminale, voglio che la riga di stato mi dica quali tasti
    hanno senso mentre la lista è aperta, così da scoprire Tab senza leggere
    il README.
16. Come persona al terminale, voglio che mentre l'Agent lavora la lista mi
    mostri solo i comandi che partiranno davvero, così da non farmi suggerire
    qualcosa che verrà rifiutato un istante dopo.
17. Come persona al terminale, voglio che mentre sto scegliendo da un Picker
    non compaia nessuna lista di comandi, così che ci sia sempre una cosa sola
    da guardare.
18. Come persona al terminale, voglio poter scorrere fino in fondo a una lista
    più lunga dello spazio disponibile, così da non perdere di vista comandi
    che esistono.
19. Come Host Application, voglio che i comandi che ho montato compaiano
    senza che io faccia niente, così da non dover costruire e mantenere una
    lista in più.
20. Come Host Application, voglio che i comandi compaiano nell'ordine in cui
    li ho montati, così da poter mettere davanti quelli che contano.
21. Come Host Application, voglio che `/help` continui a funzionare come
    prima, così da avere anche un elenco che resta nella History e si rilegge.
22. Come autore di un comando, voglio che la riga che uso per descrivermi
    compaia anche qui, così da scriverla una volta sola.

## Implementation Decisions

**Non è il Picker, ed è un modulo a parte.** Il Picker è lo stato in cui la
Conversation TUI entra mentre qualcuno sceglie: prende il focus, svuota il
composer, sospende il comando che l'ha aperto con un `DeferredFuture`. Le
Command suggestions non fanno niente di tutto ciò. Condividono con il Picker
il widget di lista sottostante e nient'altro; il codice non viene riusato,
perché quello che si riuserebbe è proprio la parte che qui non serve. I due
non sono mai aperti insieme.

**Nessuna interfaccia pubblica cambia.** Niente argomenti nuovi alla
costruzione della Conversation TUI, nessun verbo nuovo nei Controls, nessun
obbligo nuovo per chi scrive un comando: `name()` e `describe()` bastano già.
Non è configurabile e non si spegne — con zero comandi montati non compare
comunque nulla, quindi la regola di ADR 0002 resta rispettata di fatto.

**Quando si apre.** La bozza è una riga sola che comincia con `/` e non
contiene ancora spazi. Da lì la lista si aggiorna a ogni tasto. Si chiude
quando compare uno spazio (da lì in poi sono argomenti), quando la `/` iniziale
sparisce, e quando la bozza va a capo. Uno slash in mezzo a un messaggio non
apre niente.

**Cosa si guarda per filtrare.** Il nome, e solo il nome, senza distinzione fra
maiuscole e minuscole. La descrizione non entra nella ricerca.

**Come si filtra.** Sottostringa contigua: una riga resta se quello che è stato
scritto compare dentro il nome, in un punto qualsiasi. `/wind` trova
`/rewind`; `/rwd` non trova niente.

**Come si ordina.** Prima le corrispondenze esatte, poi quelle per prefisso,
poi le altre sottostringhe; a parità di categoria vale l'ordine in cui la Host
Application ha montato i comandi. L'ordinamento non è un punteggio: sono tre
insiemi in fila.

**Cosa si legge su una riga.** Il nome e la sua descrizione, sulla stessa riga,
con in grassetto il tratto di nome che corrisponde a quanto scritto. Con la
sottostringa contigua il tratto è sempre uno solo.

**Nessuna corrispondenza.** Al posto della lista compare una riga sola —
`No commands match "/xxx"` — e la fascia resta occupata. Non si chiude: che non
ci sia niente è un'informazione, e vale anche per una TUI su cui non è montato
nessun comando.

**I tasti.** ↑↓ muovono la selezione e sono sottratti al composer solo mentre
la lista è aperta, il che non costa niente perché lì la bozza è per definizione
una riga sola. Tab completa la riga selezionata. Esc chiude la lista; una
seconda Esc svuota la bozza, come oggi. **Invio non viene mai intercettato**:
manda sempre quello che è scritto. Con la riga «No commands match», e a lista
chiusa, Tab non fa niente — e in particolare non infila mai un carattere di
tabulazione nella bozza.

L'intercettazione avviene fra i listener globali della TUI, che girano prima
del widget che ha il focus e che possono fermare la propagazione: è lo stesso
meccanismo già usato per `Ctrl+C` e per le pagine su e giù, e il focus resta al
composer per tutto il tempo.

**Cosa inserisce il completamento.** Il nome seguito da uno spazio — `/model `
— così la lista si chiude da sé per la regola di apertura e il cursore è già
dove si scrivono gli argomenti.

**La selezione torna in cima** ogni volta che l'insieme delle righe mostrate
cambia. Chi digita sta restringendo, non scorrendo.

**Dove sta e quanto è alta.** Una fascia propria sopra la riga del composer,
accanto allo slot del Picker. Otto righe visibili, misurate in righe e non in
voci, con l'indicatore di scorrimento che il widget di lista disegna già da sé
quando le voci non ci stanno: niente viene tagliato in silenzio.

**Durante un Turn si elencano solo i comandi che gireranno.** La Conversation
TUI rifiuta i comandi che non dichiarano nel tipo di girare mentre l'Agent
lavora; suggerire quei nomi mentre l'Agent lavora sarebbe una promessa falsa.
Quindi mentre un Turn è in volo la lista contiene i soli `RunsWhileWorking`, e
se non ce n'è nessuno montato si finisce sulla riga «No commands match».

**Mentre il Picker è aperto non compare niente.** Lì il composer non prende
testo, quindi non c'è nessuna bozza da completare.

**Una quarta riga di stato.** Mentre la lista è aperta la riga in fondo dice
quali tasti hanno senso adesso — muoversi, completare, mandare — e torna a
`READY_STATUS` appena si chiude. È il mestiere che quella riga già svolge negli
altri tre stati.

**`/help` non cambia di una riga.** La lista vive mentre si scrive e sparisce;
`/help` lascia una nota nella History, che si rilegge e si scorre. Restano due
cose distinte, montabili indipendentemente.

## Testing Decisions

Un buon test qui prova cosa vede e cosa batte una persona al terminale, non
come è fatto dentro. Il seam è **uno solo, ed esiste già**: costruire la
Conversation TUI con un `VirtualTerminal`, simulare la digitazione, e
verificare il testo che compare una volta tolti i codici ANSI. Prior art:
`tests/NeuronCliTest.php`, che monta un Agent con un provider finto, simula i
tasti e verifica lo schermo; e `tests/Tui/ConversationViewTest.php`.

Nessun seam nuovo. In particolare la regola di corrispondenza **non** riceve
una prova propria: si monta un insieme di comandi dai nomi scelti apposta e si
verifica quali righe compaiono e in che ordine. Provarla da sola congelerebbe
l'implementazione di una cosa che è interamente osservabile dall'alto.

Provato da lì:

- digitando `/` compaiono tutti i comandi montati, ciascuno con la sua
  descrizione;
- su una TUI senza comandi montati, `/` porta alla riga che dice che non
  corrisponde niente;
- la lista si restringe a quello che si scrive, e si riallarga cancellando;
- una sottostringa in mezzo al nome trova il comando; una sequenza di lettere
  non contigue no;
- il nome scritto per intero è la prima riga, davanti a un comando che lo
  contiene;
- a parità di corrispondenza l'ordine è quello di montaggio;
- il tratto corrispondente è in grassetto nel nome;
- ↑↓ muovono la selezione, e Tab inserisce nel composer il nome selezionato
  seguito da uno spazio;
- dopo il completamento la lista non è più sullo schermo;
- Invio manda quello che è scritto anche a lista aperta, e il comando parte;
- Esc chiude la lista lasciando la bozza, e una seconda Esc la svuota;
- Tab non inserisce niente quando nessun comando corrisponde;
- uno spazio, un a capo o un messaggio normale chiudono la lista o non la
  aprono affatto;
- uno slash in mezzo a un messaggio non apre niente;
- mentre l'Agent lavora la lista contiene i soli comandi eseguibili allora;
- mentre un Picker è aperto non compare nessuna lista;
- con più comandi di quanti ce ne stiano, l'indicatore di scorrimento compare
  e ↑↓ arrivano fino all'ultimo;
- la riga di stato cambia mentre la lista è aperta e torna com'era dopo.

## Out of Scope

- Il completamento degli **argomenti** dopo il nome: la lista si chiude allo
  spazio e non sa niente di cosa segue.
- Lo slash **in mezzo** a un messaggio, che resta testo per l'Agent.
- Il **mouse**, che il componente TUI sottostante non offre qui.
- La ricerca dentro le **descrizioni** e la corrispondenza per sottosequenza o
  a punteggio: escluse con motivo, vedi Further Notes.
- Gli **alias**: un comando risponde a un nome solo, come oggi.
- Tutto ciò che riguarda le **Session** e il **Picker**, compreso il modo in
  cui si sceglie una Session: è il lavoro successivo, e questa feature non ne
  tocca una riga.
- Le altre funzionalità basiche ancora mancanti elencate in
  `docs/research/claude-code-interface-basics.md`: interrompere un Turn con
  `Esc`, la riga di stato viva, l'approvazione dei tool, la cronologia dei
  prompt, il riassunto della conversazione, i subagent.

## Further Notes

**Perché non c'è un ADR.** Nessuna interfaccia pubblica cambia e nulla è
difficile da invertire, al contrario di ADR 0002 che tolse il Session provider
dalla costruzione della TUI. La sola cosa sorprendente — che questa non è il
Picker — è scritta dove un lettore la cerca, cioè in `CONTEXT.md`.

**Dove ci si discosta da Claude Code, e perché.** La regola generale è
riprodurre il comportamento di Claude Code. Qui ci si discosta in tre punti,
ciascuno verificato sul suo terminale (v2.1.234) durante il grilling:

- *Il filtro non guarda le descrizioni.* Da loro sì, e si vede il prezzo:
  scrivendo `/cost` compare `/doctor`, che con «cost» non c'entra niente e sta
  lì per via della sua descrizione. Là serve, perché i comandi sono decine e si
  cerca per argomento; qui una Host Application ne monta cinque o dieci e sa
  come si chiamano.
- *La corrispondenza è la sottostringa contigua, non il loro punteggio.* Le
  prove dicono che il loro matcher cerca il pezzo più lungo di quanto scritto
  che stia dentro un nome e accetta sopra una soglia: `/rwd` non trova
  `/rewind`, ma `/wind` tira su anche `/keybindings` (per via di `ind`). La
  soglia non è ricavabile da fuori e sarebbe una regola che nessuno può
  prevedere leggendo il codice.
- *La lista scorre invece di essere tagliata.* Da loro una ricerca su `/co`
  mostra tre righe e lascia fuori `/compact`, `/context` e `/cost` senza
  dirlo, e con ↑↓ non ci si arriva. Il widget di lista qui offre già il
  contatore di scorrimento.

Adottato invece integralmente: la posizione sopra il composer, l'apertura solo
con lo slash come primo carattere, il grassetto sui caratteri corrispondenti,
Tab che completa, Esc che chiude, e la riga «No commands match "…"» al posto
della lista.

**Vincolo noto**, lo stesso della spec precedente: il componente TUI su cui
poggia la Conversation TUI è sperimentale, senza promessa di compatibilità né
documentazione pubblicata. Qui si poggia sul widget di lista — lo stesso su cui
poggia già il Picker — e sull'ordine in cui i listener globali ricevono i tasti
prima del widget che ha il focus.
