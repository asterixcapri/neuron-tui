# 03: Compose shared modules explicitly and mount through Commands

**What to build:** A Host Application starts the TUI with a required Agent and
independently optional interaction modules. It builds its Command collection
through Commands itself, and supplied objects remain the objects used by the
running interaction.

**Blocked by:** 01 — Share Help and Leave and simplify concurrent Command policy.

**Status:** ready-for-agent

Parent: Refine Interaction composition and shared Commands, the spec in this
feature directory.

- [ ] Tui construction and its equivalent make factory accept a required configured Agent, optional Terminal, and independently optional Commands, Sessions and InputHistory.
- [ ] Supplied modules are reused without copies or reconstruction from their Storage. Runtime and Command controls use those same instances.
- [ ] Omitted Commands defaults to an empty collection; omitted Sessions and InputHistory use InMemoryStorage. Defaults are created once per TUI instance and preserve state across invocations.
- [ ] Agent-only startup remains supported, with no automatically mounted Help, Leave or Session Commands.
- [ ] Commands::addCommand mutates the existing collection and returns $this. Constructor mounting remains available, and both forms accept individual Commands, Command kits and mixed arrays with the same validation.
- [ ] Mounting preserves order, immediate rejection of invalid members and first-match duplicate dispatch. All applicable identifier validation is enforced equally on constructor and incremental mounting.
- [ ] Tui::addCommand is removed. TUI Storage configuration is replaced by Host Application construction of the modules it supplies, with no competing setStorage ownership path.
- [ ] Commands are configured before run. No immutable-return behavior, copy, collection freeze, lock or live suggestion synchronization is introduced.
- [ ] Existing branding, optional Terminal support, single-run lifecycle and restrictions on changing TUI configuration after startup remain intact.
- [ ] No Interaction facade or service container is introduced. Existing InputHistory navigation stays on InputHistory, with instance-local cursor/draft and unchanged persistence behavior.
- [ ] Public Commands tests verify mutation, return identity, kits, arrays, validation and ordering. Virtual-terminal integration tests verify independently supplied or omitted modules, identity reuse, persistent default state and empty Commands.
- [ ] Examples, consumer locks and relevant documentation use module composition; affected ADR-0003 and ADR-0005 composition decisions are explicitly superseded. Relevant tests and static analysis pass in both repositories.

## Execution notes

Ticket 01 removes the separate concurrent contract so the supplied Commands
collection can represent every mounted Command directly. Ticket 02 is not a
prerequisite: use whichever identifier convention has landed and keep both
mounting forms consistent with it. Startup History preservation and recovery
are completed by ticket 04, not silently claimed here. Use the existing
feat/extract-neuron-interaction branches and PRs; no merge or release.
