# 02: Rinominare integralmente il package in Neuron TUI

**What to build:** Dare all'intera libreria l'identità Neuron TUI attraverso
un refactor coordinato. Il package Composer diventa
`asterixcapri/neuron-tui`, ogni tipo pubblico e interno passa sotto
`NeuronTui`, e l'entry point diventa il final `NeuronTui\Tui`. Il comportamento
terminale resta quello preparato dal ticket precedente; cambia soltanto
l'identità con cui la Host Application installa e usa la libreria.

**Blocked by:** 01/Separare configurazione e runtime della Conversation TUI.

**Status:** ready-for-agent

- [ ] Il package Composer si chiama `asterixcapri/neuron-tui` e la sua
      descrizione presenta una TUI riusabile per Agent Neuron AI.
- [ ] Tutti i tipi del package, inclusi quelli interni, di test e di supporto
      all'analisi statica, usano il namespace radice `NeuronTui`.
- [ ] L'entry point pubblico è il final `NeuronTui\Tui` e conserva, in questa
      slice, il comportamento osservabile dell'entry point precedente.
- [ ] Non vengono forniti alias, classi ponte, mapping legacy o altre forme di
      compatibilità con il vecchio package, namespace o entry point.
- [ ] Documentazione ed esempi correnti usano Neuron TUI e il nuovo entry
      point; i riferimenti puramente storici negli ADR restano chiaramente tali.
- [ ] L'autoload Composer risolve il nuovo namespace e non risolve quello
      precedente.
- [ ] La suite completa e l'analisi statica passano dopo il refactor
      coordinato.
