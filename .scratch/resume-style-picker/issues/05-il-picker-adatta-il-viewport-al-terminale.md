# 05: Il Picker adatta il viewport al terminale

**What to build:** Il Picker conserva il ritmo visivo di `/resume` senza
coprire inutilmente la History o tagliare un'opzione quando il terminale è
basso. La selezione resta visibile e ogni opzione rimane raggiungibile durante
scorrimento, ricerca e ridimensionamento.

**Blocked by:** 04/Le scelte lunghe espongono la ricerca.

**Status:** ready-for-agent

- [ ] Il viewport mostra al massimo quattro blocchi di opzione completi.
- [ ] Quando lo spazio verticale non basta, il viewport mostra meno blocchi
      anziché tagliare label, detail o la separazione necessaria a leggerli.
- [ ] Opzioni senza detail, con detail e con testo mandato a capo possono
      convivere nello stesso viewport senza sovrapporsi o essere spezzate.
- [ ] Up e Down mantengono visibile l'opzione selezionata e rendono
      raggiungibili tutte le opzioni in entrambe le direzioni.
- [ ] Il wrap fra prima e ultima opzione porta con sé il viewport corretto.
- [ ] Una variazione della query ricostruisce il viewport dalla prima
      corrispondenza senza lasciare righe appartenenti ai risultati precedenti.
- [ ] Un ridimensionamento del terminale ricalcola quanti blocchi completi
      entrano e richiede un nuovo rendering senza perdere query o selezione.
- [ ] Un terminale molto basso conserva almeno l'opzione attiva e le azioni
      necessarie per scegliere o annullare, senza lasciare la TUI in uno stato
      irrecuperabile.
- [ ] Chiusura, annullamento e riapertura non conservano offset o viewport
      della scelta precedente.
- [ ] Terminali normali, stretti e bassi sono verificati attraverso lo Slash
      command e `VirtualTerminal`; i test osservano blocchi, selezione e
      risultati invece della struttura interna del renderer.
