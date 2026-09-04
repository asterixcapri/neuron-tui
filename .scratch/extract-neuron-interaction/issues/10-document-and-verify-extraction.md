# 10: Document and verify the extracted package

**What to build:** Give maintainers and Host Application developers a verified description of how to compose Neuron Interaction from TUI and backend environments, with the final package boundaries enforced by both test suites.

**Blocked by:** 09: Switch Neuron TUI to Neuron Interaction.

**Status:** ready-for-agent

- [ ] Neuron Interaction documentation explains direct composition of Commands, Sessions, Input history and Storage without a mandatory facade.
- [ ] Documentation demonstrates how a backend Adapter implements Command Controls and carries a Selection request across separate requests without prescribing a framework.
- [ ] Documentation explains that `promptAgent` hands work to the Host Application's normal Agent flow and that Agent response streaming is outside the package.
- [ ] Neuron TUI documentation uses neutral Command identifiers at the package boundary and slash syntax only in terminal examples.
- [ ] Documentation identifies terminal-only Commands and concurrency policy as Neuron TUI concerns.
- [ ] Both package suites pass static analysis and tests independently.
- [ ] The final dependency graph contains no reverse dependency from Neuron Interaction to Neuron TUI.
- [ ] Authentication, subagent orchestration, worker topology and legacy persistence compatibility remain absent from the delivered scope.
