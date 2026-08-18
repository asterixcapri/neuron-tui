# Spec: Slash command programmabili

Status: ready-for-agent

Design di riferimento: `docs/research/slash-command-api.md`.
Vocabolario: `CONTEXT.md` (Slash command, Controls, Command kit, Picker,
Session, Session provider, Host Application, Conversation TUI, Turn).

## Problem Statement

Una Host Application che costruisce un Agent con Neuron AI e vuole dargli un
terminale non può decidere cosa ci si digita dentro. La Conversation TUI
conosce tre Slash command — `/clear`, `/sessions`, `/exit` — e non c'è modo di
aggiungerne un quarto: il sorgente dichiara esplicitamente che un insieme
fisso non giustifica un registro.

Questo lascia fuori tutto ciò che rende utile un terminale su un Agent
specifico: mandare all'Agent il diff in staging, iniettare un file, cambiare
modello, aggiungere un toolkit, aprire una issue del tracker locale. Ognuna
di queste è codice che l'host sa già scrivere, ma che non ha nessun posto
dove essere montata.

C'è anche un difetto che pesa sull'usabilità: chi apre la TUI non ha modo di
scoprire quali Slash command esistono, e digitare `/exit now` non esce, perché
un comando è riconosciuto solo se coincide con l'intero input.

## Solution

Gli Slash command diventano oggetti che una Host Application monta al momento
di costruire la Conversation TUI. La TUI non ne monta nessuno da sé: parte
con la lista vuota, e chi vuole `/clear` lo chiede.

Un comando dichiara il nome per cui risponde e una riga che lo descrive, e
quando qualcuno lo digita riceve i **Controls**: un insieme ristretto di cose
che può fare — dire qualcosa nella conversazione, mandare un prompt
all'Agent, offrire un Picker, raggiungere l'Agent, cambiarlo, elencare i
comandi montati, uscire.

La libreria continua a fornire i comandi che ha oggi — più `/help` — come
classi pronte, montabili singolarmente o in gruppo tramite un **Command kit**
sul modello dei toolkit di Neuron AI.

Un comando riceve anche gli argomenti scritti dopo il nome, il che rende
`/exit now` un `/exit` con argomenti invece di un comando sconosciuto.

## User Stories

1. Come Host Application, voglio montare i miei Slash command quando
   costruisco la Conversation TUI, così che il terminale sappia fare le cose
   che servono alla mia applicazione.
2. Come Host Application, voglio che la TUI non monti nessun comando da sé,
   così che nel terminale ci sia solo quello che ho scelto.
3. Come Host Application, voglio costruire la TUI senza nominare nessun
   comando, così che il caso semplice resti una riga.
4. Come Host Application, voglio dichiarare per ogni comando il nome e una
   descrizione di una riga, così che possa essere elencato a chi lo usa.
5. Come Host Application, voglio ricevere gli argomenti digitati dopo il nome
   del comando, così che `/model haiku` possa saltare la scelta.
6. Come persona al terminale, voglio che `/exit now` esca invece di essere
   respinto come comando sconosciuto, così che uno spazio di troppo non sia un
   errore.
7. Come persona al terminale, voglio digitare `/help` e vedere l'elenco dei
   comandi montati con la loro descrizione, così da sapere cosa posso fare.
8. Come Host Application, voglio montare `/help` senza passargli niente, così
   da non dover costruire la lista dei comandi prima di averla.
9. Come persona al terminale, voglio che un nome che nessun comando risponde
   me lo dica, invece di essere mandato all'Agent.
10. Come autore di un comando, voglio scrivere una riga nella conversazione,
    così da poter riferire cosa è successo.
11. Come autore di un comando, voglio segnalare che qualcosa non è andato,
    così che si distingua da un esito normale.
12. Come autore di un comando, voglio mandare un prompt all'Agent, così che il
    comando possa far lavorare il modello su qualcosa che ha preparato lui.
13. Come autore di un comando, voglio offrire un elenco alla persona e sapere
    cosa ha scelto, così da poter chiedere fra più alternative.
14. Come autore di un comando, voglio sapere che la persona ha annullato la
    scelta, così da non fare niente in quel caso.
15. Come autore di un comando, voglio raggiungere l'Agent, così da cambiargli
    modello, istruzioni o tool con l'API di Neuron AI invece che con verbi
    duplicati qui.
16. Come autore di un comando, voglio installare un'altra History sull'Agent,
    così da poter cambiare conversazione.
17. Come autore di un comando, voglio far rispondere un altro Agent da qui in
    avanti, così da poter passare da un agente all'altro senza uscire.
18. Come autore di un comando, voglio che la conversazione in corso resti
    quella anche quando cambio Agent, così che il lavoro fatto non vada perso.
19. Come autore di un comando, voglio poter far uscire dalla TUI, così da
    poter scrivere il mio `/quit`.
20. Come autore di un comando, voglio elencare i comandi montati, così da
    poterne scrivere uno che li mostra.
21. Come persona al terminale, voglio che lo schermo dica la verità dopo che un
    comando ha girato, anche se ha cambiato conversazione o Agent.
22. Come persona al terminale, voglio che un comando difettoso mi mostri
    l'errore e mi lasci al terminale, invece di far morire tutto.
23. Come persona al terminale, voglio che un comando che cambierebbe la
    conversazione sia rifiutato mentre l'Agent sta rispondendo, così che una
    risposta non atterri dove non appartiene.
24. Come persona al terminale, voglio poter uscire e chiedere aiuto anche
    mentre l'Agent sta rispondendo, così da non dover aspettare.
25. Come autore di un comando eseguibile mentre l'Agent lavora, voglio che il
    tipo mi impedisca di aprire un Picker, così da non poter scrivere per
    sbaglio un caso che nessuno vuole.
26. Come Host Application, voglio montare in una riga un gruppo di comandi che
    vanno insieme, così da non elencarli uno per uno.
27. Come Host Application, voglio che un gruppo si porti dietro ciò che serve
    ai suoi comandi, così da nominare il Session provider una volta sola.
28. Come Host Application, voglio montare un gruppo lasciandone fuori qualche
    comando, così da avere `/sessions` senza `/clear`.
29. Come Host Application, voglio dare a un comando fornito un nome mio, così
    da avere `/quit` al posto di `/exit`.
30. Come Host Application, voglio essere fermata subito se due comandi
    rispondono allo stesso nome, invece di scoprire a runtime quale ha vinto.
31. Come Host Application, voglio decidere dove vivono le conversazioni
    passando il Session provider ai comandi che lo usano, così che quel posto
    sia nominato dove serve.
32. Come persona al terminale, voglio scegliere da un elenco allo stesso modo
    che si tratti di Session, modelli o altro, così da imparare un gesto solo.
33. Come manutentore, voglio che i comandi non raggiungano i widget della
    Conversation TUI, così che restino liberi di cambiare.

## Implementation Decisions

**Nessun comando montato d'ufficio.** La Conversation TUI parte con la lista
vuota. `Clear`, `Sessions`, `Leave` e `Help` restano forniti dalla libreria
come classi, e vengono montati solo se la Host Application li nomina. Senza
`Leave` si esce solo con `Ctrl+C`: è una conseguenza accettata e va detta nel
README.

**Due interfacce di comando, non un marcatore.** Un comando che pretende che
l'Agent stia fermo e uno eseguibile mentre l'Agent lavora ricevono oggetti
diversi, quindi la distinzione non può essere un'interfaccia vuota: cambia la
firma di `run()`. Le due estendono una comune che porta solo identità e
descrizione.

```php
interface Command {
    public function name(): string;      // '/review'
    public function describe(): string;  // una riga, per /help
}

interface SlashCommand extends Command {
    public function run(Controls $controls, string $arguments): void;
}

interface RunsWhileWorking extends Command {
    public function run(LimitedControls $controls, string $arguments): void;
}
```

**I Controls sono otto verbi.** `say`, `warn`, `ask`, `choose`, `agent`,
`useAgent`, `commands`, `stop`. I `LimitedControls` ne hanno quattro: `say`,
`warn`, `commands`, `stop` — niente Picker, niente Agent, perché sta
rispondendo. La Conversation TUI resta dietro: nessun comando raggiunge i suoi
widget.

**`ask()` manda e finisce.** Non ritorna la risposta e non sospende. Se
servirà aspettare, arriverà un verbo suo: cambiare il significato di questo
renderebbe ambiguo il caso semplice.

**`choose()` sospende.** È l'unico verbo che lo fa. Torna la chiave scelta, o
niente se la persona annulla. La Conversation TUI e amphp girano sullo stesso
event loop, quindi la sospensione si costruisce sopra a quello; il Picker
resta a callback e viene fatto da ponte.

**Cambiare Agent non tocca le Session.** Una conversazione non appartiene a un
Agent: `useAgent` passa al nuovo Agent la History corrente e da lì in avanti è
lui a rispondere. Un comando che sa di essere incompatibile — tool diversi,
provider diverso — installa una History nuova invece.

**Il Session provider esce dalla Conversation TUI.** Non è più un argomento di
costruzione: lo ricevono i comandi che lo usano. Di conseguenza i verbi non
includono niente sulle Session: aprire una conversazione è installare una
History sull'Agent.

**Riconciliazione dopo ogni comando.** Finito `run()`, la Conversation TUI
ridisegna la History leggendola dall'Agent. Così qualunque cosa il comando
abbia fatto — cambiato conversazione, cambiato Agent — lo schermo e l'Agent
concordano senza che il comando debba dichiararlo.

**Un comando difettoso non uccide la TUI.** L'esecuzione è protetta: quello
che risale diventa una riga di errore nella conversazione, come già accade per
un'eccezione durante un Turn.

**Il rifiuto durante un Turn resta la regola.** Un comando che non è
eseguibile mentre l'Agent lavora viene respinto con un messaggio che invita a
riprovare a Turn finito. La regola vale per la Conversation TUI, non per il
singolo comando: è la TUI a decidere, in base al tipo.

**Interpretazione dell'input.** Ciò che comincia con `/` produce sempre un
nome più gli argomenti che seguono; il nome è la prima parola, gli argomenti
sono il resto ripulito dagli spazi ai bordi. Il modulo che interpreta smette
di conoscere i nomi esistenti: la ricerca avviene nel registro. Un messaggio
per l'Agent resta intatto, slash e spaziatura inclusi.

**Registro dei comandi.** La Conversation TUI monta la lista una volta,
indicizzata per nome. Due comandi con lo stesso nome sono un errore di
costruzione, non un override silenzioso. Un Command kit viene srotolato al
montaggio: dopo, un comando montato singolarmente e uno arrivato da un kit
sono indistinguibili.

**Command kit.** Un gruppo di comandi montabile in una riga, che tiene ciò che
serve ai suoi membri — il Session provider, per il kit delle Session — e da cui
si possono escludere alcuni membri o tenerne solo alcuni. Il vocabolario evita
"toolkit", che in Neuron AI significa un gruppo di tool per il modello.

**Nomi personalizzabili.** Ogni comando fornito accetta il proprio nome alla
costruzione, con un default. Questo permette `/quit` invece di `/exit` senza
riscrivere il comando.

**Il Picker perde la Session.** Diventa una lista che prende un titolo e
coppie chiave/etichetta e restituisce la chiave scelta. La traduzione da
Session a etichetta scende nel comando che elenca le Session. Il widget di
lista sottostante è già generico, quindi si tratta di smettere di
specializzarlo.

**L'Agent smette di essere immutabile nella Conversation TUI**, e chi esegue
un Turn lo riceve al momento invece di tenerlo, così che un cambio di Agent
valga dal Turn successivo.

**Coda dei messaggi, History, Session, Session provider, indicatore di
lavoro**: invariati.

## Testing Decisions

Un buon test qui prova cosa vede una persona al terminale, non come è fatto
dentro. Il seam giusto esiste già ed è il più alto possibile: costruire la
Conversation TUI con un terminale virtuale, simulare la digitazione, e
verificare il testo che compare una volta tolti i codici ANSI. L'Agent viene
da un provider finto. Prior art: le prove esistenti della TUI, che già montano
un Agent, simulano `Ctrl+C` e verificano l'intestazione.

Provato da lì:

- un comando montato dalla Host Application viene eseguito quando lo si digita,
  e riceve gli argomenti che lo seguono;
- un nome che nessun comando risponde lo dice, e non raggiunge l'Agent;
- senza comandi montati, ogni nome è sconosciuto;
- `/help` elenca i comandi montati con le descrizioni, se stesso compreso;
- ciò che un comando dice, e ciò di cui avverte, compare nella conversazione;
- il prompt che un comando manda all'Agent produce una risposta sullo schermo;
- il Picker offerto da un comando si muove, filtra, sceglie e annulla;
- un comando che va in errore lascia una riga di errore e il terminale vivo;
- un comando non eseguibile durante un Turn viene rifiutato con l'invito a
  riprovare, mentre uno eseguibile gira lo stesso;
- dopo un comando che installa un'altra History, lo schermo mostra quella;
- dopo un cambio di Agent, la conversazione in corso è ancora sullo schermo e
  la risposta successiva viene dal nuovo Agent;
- un Command kit monta i suoi membri, e l'esclusione ne lascia fuori qualcuno;
- due comandi con lo stesso nome fermano la costruzione.

Provato più in basso, dove il seam esiste già: l'interpretazione dell'input —
nome e argomenti, `/exit now`, `/exit ` con lo spazio in fondo, un messaggio
che comincia per slash ma non risponde a nessun comando, un messaggio normale
lasciato intatto.

Non ricevono prove proprie: Controls, LimitedControls, Command kit, Picker.
Sono raggiungibili dall'alto, e provarli separatamente congelerebbe
l'implementazione. Per verificare cosa un comando riceve si monta un comando
di prova che registra quello che gli è arrivato: è un doppio di test, non un
seam nuovo.

## Out of Scope

Questo lavoro rende programmabili gli Slash command. Non fa nessuna delle
altre funzionalità base descritte in `docs/research/claude-code-interface-basics.md`:

- interrompere un Turn con `Esc` senza uscire dalla TUI;
- la riga di stato viva, con i token consumati;
- l'approvazione dei tool, che Neuron AI offre ma che non è agganciata;
- la cronologia dei prompt digitati;
- il riassunto e la compattazione della conversazione;
- il testo integrale di una chiamata a tool, oggi troncato;
- i subagent, cioè far lavorare un secondo Agent di lato;
- il completamento automatico quando si digita `/`;
- attendere dentro un comando la risposta dell'Agent.

Il design regge i subagent con un verbo in più e l'attesa con un altro: sono
rimandati, non esclusi.

## Further Notes

Questo lavoro inverte una decisione scritta nel sorgente — che un insieme
fisso di Slash command non giustifica un registro — e cambia l'interfaccia
pubblica della libreria togliendo il Session provider dalla costruzione della
Conversation TUI. Merita un ADR accanto a quello sulle Session.

Niente è mai stato rilasciato, quindi la rottura di interfaccia non ha costo
per nessuno.

Vincolo noto: il componente TUI su cui poggia la Conversation TUI è
sperimentale, senza promessa di compatibilità né documentazione pubblicata, e
il ramo successivo a quello in uso rompe già il costruttore del widget di
lista su cui poggia il Picker. Il ponte che fa sospendere `choose()` è il
punto tecnicamente più rischioso di tutto il lavoro, ed è l'unico punto
sospensivo del design.
