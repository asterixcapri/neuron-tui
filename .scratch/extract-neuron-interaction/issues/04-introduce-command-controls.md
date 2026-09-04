# 04: Replace Controls with Adapter-owned Command Controls

**What to build:** Let every ordinary Command operate through a presentation-independent `CommandControls` contract implemented by the active Adapter, without losing any existing Command capability.

**Blocked by:** 03: Introduce neutral Command dispatch.

**Status:** ready-for-agent

- [ ] `CommandControls` exposes `say`, `warn`, `promptAgent`, `requestSelection`, `agent`, `useAgent`, `commands`, `sessions` and `stop`.
- [ ] The former `ask` behavior is available only as `promptAgent` and still feeds the normal Agent turn flow without returning its response.
- [ ] `commands` returns the shared `Commands` collection rather than a raw array.
- [ ] Commands return `void` and do not define domain-specific result types.
- [ ] Command-specific dependencies continue to arrive through Command constructors.
- [ ] Neuron TUI supplies an Adapter implementation without exposing its view or widgets to Commands.
- [ ] Generated Agent prompts stay out of Input history while the submitted Command invocation remains recorded.
- [ ] Existing notice, warning, Agent replacement, stopping and failure-survival behavior remains covered at public seams.

