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

**Status:** ready-for-agent

- [ ] Un comando può dire quale Agent risponde da qui in avanti
- [ ] Dopo il cambio, la conversazione che era sullo schermo è ancora lì
- [ ] La risposta successiva viene dal nuovo Agent
- [ ] Un comando può installare una History diversa sull'Agent
- [ ] Un Turn già cominciato finisce con l'Agent che lo aveva preso
