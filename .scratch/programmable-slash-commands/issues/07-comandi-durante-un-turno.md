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

**Status:** ready-for-agent

- [ ] Un comando che dichiara di poter girare durante un Turn viene eseguito
      mentre l'Agent risponde
- [ ] Un comando che non lo dichiara viene rifiutato durante un Turn, con
      l'invito a riprovare a Turn finito, e non viene eseguito
- [ ] I comandi che girano durante un Turn ricevono soltanto il dire,
      l'avvertire, l'elencare e l'uscire
- [ ] Aprire un Picker da un comando di quel genere non è esprimibile
- [ ] Uscire e chiedere aiuto funzionano sia a Turn fermo sia a Turn in corso
- [ ] La risposta in corso non viene disturbata da un comando eseguito nel
      frattempo
