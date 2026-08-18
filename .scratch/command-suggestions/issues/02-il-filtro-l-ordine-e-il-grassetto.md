# 02 — Il filtro, l'ordine e il grassetto

**What to build:** La lista si restringe a quello che si sta scrivendo, e dice
perché ogni riga è rimasta.

Una riga resta se quello che è stato scritto compare **dentro il nome**, in un
punto qualsiasi e di fila: `/wind` trova `/rewind`, `/ses` trova `/sessions`,
`/rwd` non trova niente. Maiuscole e minuscole non contano. La descrizione non
entra nella ricerca: si cerca fra i nomi, non fra i significati.

Quello che resta è ordinato in tre insiemi in fila — prima le corrispondenze
esatte, poi quelle che cominciano per quanto scritto, poi le altre — e dentro
ciascuno vale l'ordine in cui la Host Application ha montato i comandi. Non è
un punteggio: chi legge il codice deve poter prevedere l'ordine senza eseguirlo.

Dentro ogni nome, il tratto che corrisponde è in grassetto, così si vede a
colpo d'occhio perché quella riga è lì. Con la sottostringa contigua il tratto
è sempre uno solo. Quando non resta nessuno, al posto della lista compare la
riga che lo dice, con quanto è stato scritto.

**Blocked by:** 01 — La lista compare mentre si scrive un nome.

**Status:** claimed

- [ ] Scrivendo dopo lo slash la lista tiene solo i comandi il cui nome
      contiene quello che si è scritto
- [ ] La corrispondenza è insensibile a maiuscole e minuscole
- [ ] Una sottostringa in mezzo al nome trova il comando; lettere non contigue
      no
- [ ] La descrizione non fa comparire un comando che il nome non farebbe
      comparire
- [ ] Il nome scritto per intero è la prima riga, davanti a un comando che lo
      contiene
- [ ] Un comando che comincia per quanto scritto sta davanti a uno che lo
      contiene soltanto
- [ ] A parità di corrispondenza l'ordine è quello di montaggio
- [ ] Il tratto corrispondente compare in grassetto dentro il nome
- [ ] Quando nessun comando resta, compare la riga `No commands match "/…"`
      con quanto è stato scritto
- [ ] Cancellando, la lista si riallarga
