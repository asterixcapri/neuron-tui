# 04: Eseguire più Subagent con concorrenza limitata

**What to build:** consentire a più Subagent di lavorare davvero in parallelo
entro un limite configurabile, mantenendo prevedibili gli stati e senza
riservare un processo agli ID inattivi.

**Blocked by:** 02: Avviare un Subagent in background.

**Status:** ready-for-agent

- [ ] Il limite di concorrenza è configurabile dal toolkit e vale per i Turn
      figli attivi, non per il numero di Subagent registrati.
- [ ] Il limite predefinito è quattro Turn figli concorrenti.
- [ ] Turn appartenenti a Subagent diversi possono essere eseguiti
      contemporaneamente fino al limite.
- [ ] Il lavoro oltre il limite rimane `queued` e parte quando si libera
      capacità.
- [ ] Non vengono mai eseguiti contemporaneamente due Turn dello stesso
      Subagent.
- [ ] Un Subagent `idle` conserva ID e History ma non occupa un worker.
- [ ] Terminato un Turn, il worker torna al pool e un Turn successivo dello
      stesso Subagent può usare un worker diverso.
- [ ] Il rilascio della capacità avviene anche quando un'esecuzione fallisce.
- [ ] I test provano sovrapposizione reale, rispetto del limite e avanzamento
      della coda senza affidarsi soltanto a soglie temporali.

