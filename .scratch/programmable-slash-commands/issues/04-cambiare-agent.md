# 04 — Un comando può cambiare Agent

**What to build:** Una Host Application che configura più Agent può scrivere
un comando che passa dall'uno all'altro senza uscire dal terminale. Fra i
Controls compare il verbo che dice quale Agent risponde da qui in avanti.

La conversazione in corso non si perde e non si lascia: passa al nuovo Agent,
che continua a scriverci. Sullo schermo non cambia niente al momento del
passaggio — è la risposta successiva a venire da un altro Agent. Un comando
che sa di passare a un Agent incompatibile, con tool o provider diversi, può
installare una History nuova invece: quello lo decide il comando, non la
Conversation TUI.

Perché sia possibile, l'Agent smette di essere immutabile per la durata del
processo e chi esegue un Turn lo riceve al momento invece di tenerlo, così che
il cambio valga dal Turn successivo e non a metà di una risposta.

**Blocked by:** 02 — La Host Application può montare un suo comando.

**Status:** resolved

- [x] Un comando può dire quale Agent risponde da qui in avanti
- [x] Dopo il cambio, la conversazione che era sullo schermo è ancora lì
- [x] La risposta successiva viene dal nuovo Agent
- [x] Un comando può installare una History diversa sull'Agent
- [x] Un Turn già cominciato finisce con l'Agent che lo aveva preso

## Esito

Fra i Controls compare `useAgent(Agent $agent)`: il comando dice chi risponde
da qui in avanti, e la Conversation TUI passa al nuovo Agent la History che
il precedente stava rispondendo (`NeuronCli::answerFrom()`). Sullo schermo
non cambia niente al momento del passaggio — è la risposta successiva a
venire da un altro Agent. Un comando che sa di passare a un Agent
incompatibile installa una History nuova da sé, con
`$controls->agent()->setChatHistory(...)`: `agent()` risolve l'Agent al
momento della chiamata, quindi dopo `useAgent()` è già il nuovo.

Perché fosse possibile, l'Agent di `NeuronCli` smette di essere `readonly`, e
`AgentTurn` smette di tenerlo: `respond(Agent $agent, string $message)` lo
riceve al momento, e `tick()` lo legge quando il Turn parte, così che un Turn
già in volo finisca con l'Agent che lo aveva preso. `Controls` riceve dalla
TUI due closure — l'Agent corrente e il passaggio di consegne — invece
dell'Agent, e resta senza stato.

Prove, dal terminale virtuale in `tests/NeuronCliTest.php`: dopo il cambio la
conversazione precedente è ancora sullo schermo, il nuovo provider riceve la
conversazione intera e risponde lui; e il comando che installa una History
nuova sul nuovo Agent gli fa ricevere il solo messaggio successivo. Più in
basso, in `tests/Conversation/AgentTurnTest.php`, ogni Turn è risposto
dall'Agent che gli è stato passato in quel momento: dall'alto il caso a metà
di una risposta non è ancora esprimibile, perché un comando è rifiutato
durante un Turn finché non arriva il ticket 07.

README (verbo `useAgent()`) e CONTEXT.md (voce Controls) aggiornati.

Verifica: `composer test` 116 test, 382 asserzioni, verde; `composer stan`
(due configurazioni) senza errori.
