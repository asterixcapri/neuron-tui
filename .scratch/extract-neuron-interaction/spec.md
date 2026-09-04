# Extract Neuron Interaction

Status: completed

Implementation naming: the shared controls contract is
`CommandControlsInterface`, following the repository's required `Interface`
suffix. The concrete Session Command kit is `SessionCommandKit`, following
`AbstractCommandKit`; earlier conceptual references below use the original
names. The extraction is implemented on development branches, not released.

Navigation refinement: `InputHistory` also provides optional `older()`,
`newer()`, `isNavigating()` and `leave()` methods. Cursor and draft are local
to each instance and are never persisted. Adapters control the lifecycle and
may navigate the sequence themselves; no separate navigator class is needed.
This supersedes the earlier tickets' requirement to keep navigation code in
the TUI.

## Problem Statement

Neuron TUI currently owns reusable interaction concerns together with terminal
presentation and Agent turn execution. Sessions, Commands, Input history and
Storage are useful outside a terminal, but their current placement and some of
their interfaces bind them to TUI concepts such as slash syntax, synchronous
choice, terminal views and History projection.

A Host Application that offers both a terminal interface and a web backend
cannot share the same Sessions, Commands and Input history without depending
on Neuron TUI or reproducing those behaviors. In particular, a web interaction
may end one HTTP request while waiting for a selection, and Agent responses may
be streamed or executed by infrastructure that the shared interaction model
must not own.

## Solution

Extract a separate Composer library named `asterixcapri/neuron-interaction`
with the root namespace `NeuronInteraction`. It will provide headless Command,
Session, Input history and Storage modules directly, without a top-level
facade. Neuron TUI will become an interaction Adapter over those modules, and a
web backend will be able to provide another Adapter without depending on
terminal code.

Commands will receive presentation-independent `CommandControls`. Commands
will use its shared verbs for visible effects and interaction state, return no
domain-specific result, and be dispatched through a `Commands` collection that
reports only a technical `CommandExecution`. A selection will be modeled as a
non-blocking `SelectionRequest`; the Adapter will present it and invoke the
same Command again with the selected value as `CommandArguments`.

Sessions and Input history will share a configured Storage but remain separate
concepts. Sessions will own persisted Agent History and shared recognition
metadata. Input history will persist submitted inputs globally across Sessions
and Adapters, while navigation state will remain local to each Adapter.

## User Stories

1. As a Host Application developer, I want to install interaction behavior without installing a terminal UI, so that I can build a web backend around Neuron AI.
2. As a Host Application developer, I want the same interaction library in terminal and web applications, so that their core behavior does not diverge.
3. As a Neuron TUI maintainer, I want terminal presentation separated from interaction state, so that changes to the TUI do not alter shared domain behavior.
4. As a backend developer, I want to use the library without a mandatory application facade, so that I can compose only the modules my application needs.
5. As a user moving from TUI to web, I want to reopen the same Sessions, so that I can continue earlier conversations.
6. As a user moving between Adapters, I want my submitted Input history to remain available, so that I can recall and resubmit earlier inputs.
7. As a user, I want Input history to include both messages and Command invocations, so that it reflects what I actually submitted.
8. As a user, I want Input history independent from the active Session, so that changing Session does not replace my recall history.
9. As an Adapter developer, I want to own the Input history navigation cursor and draft, so that simultaneous interfaces do not overwrite each other's transient navigation state.
10. As a Host Application developer, I want generated Agent prompts excluded from Input history, so that the history records submitted input rather than internal Command expansion.
11. As a Command author, I want presentation-neutral Command identifiers, so that one Command can be invoked with slash syntax, an HTTP route or another interface.
12. As a TUI user, I want slash syntax to remain a terminal concern, so that existing terminal interaction stays natural without leaking slash prefixes into the shared model.
13. As a Command author, I want raw textual arguments represented by a dedicated value, so that today's simple interface can evolve without changing every Command signature.
14. As a Command author, I want access to the answering Agent and Sessions, so that Commands can coordinate shared interaction state.
15. As a Command author, I want to say and warn through shared controls, so that each Adapter can present those effects appropriately.
16. As a Command author, I want to prompt the Agent through `promptAgent`, so that a Command can initiate the normal Agent response flow without owning that flow.
17. As a Command author, I want to inspect and replace the answering Agent, so that Commands can change which configured Agent continues the interaction.
18. As a Command author, I want access to the mounted `Commands` collection, so that features such as help can enumerate the effective Command set without receiving a raw array.
19. As a Command author, I want to stop the interaction through shared controls, so that an Adapter can apply its own lifecycle behavior.
20. As a Command author, I want Command-specific services supplied through my constructor, so that `CommandControls` does not become a container for arbitrary dependencies.
21. As an Adapter developer, I want a selection request to return immediately, so that an HTTP request or event callback never has to block for human input.
22. As an Adapter developer, I want each Selection request to identify the Command to resume, so that I can continue it in a later interaction.
23. As a user, I want each Selection option to have a stable value, visible label and optional description, so that all Adapters can present the same choice clearly.
24. As a Command author, I want the selected value returned as new Command arguments, so that controls do not retain hidden selection state between executions.
25. As a backend developer, I want Selection requests to be serializable, so that a frontend can present them and submit the choice in a later API call.
26. As a TUI user, I want a Selection request rendered through the existing Picker experience, so that the extracted model does not degrade terminal interaction.
27. As a Command author, I want successful Commands to return no semantic result object, so that adding a Command does not require adding a new Adapter-specific result handler.
28. As an Adapter developer, I want one technical Command execution outcome, so that completed, unknown and failed dispatches are handled consistently.
29. As an Adapter developer, I want Command exceptions captured by the dispatcher, so that the interaction can survive failures without duplicating execution guards.
30. As a developer, I want an unknown Command reported as a technical outcome rather than an exception, so that invalid user input follows normal control flow.
31. As a Host Application developer, I want Commands preserved in mounting order, so that presentation remains predictable.
32. As a Host Application developer, I want the first mounted duplicate identifier to execute, so that the established duplicate rule remains deterministic.
33. As a Session user, I want starting a Session to create a distinct empty Agent History, so that conversations remain separate.
34. As a Session user, I want stored Sessions listed most-recently-used first, so that recent conversations are easy to find.
35. As a Session user, I want to resume a Session by key, so that its Agent History can be installed on the answering Agent.
36. As a user, I want a Session title derived from its first non-empty user-authored content, so that the same conversation is recognizable in every Adapter.
37. As an Adapter developer, I want Session titles to remain presentation-neutral, so that terminal escaping and web rendering stay under Adapter control.
38. As a Host Application developer, I want Session Commands supplied by the interaction library, so that clear and resume behavior is shared across Adapters.
39. As a Host Application developer, I want to mount the Session Command kit in one operation, so that native Session behavior is easy to opt into.
40. As a web backend developer, I want resume without a key to request a selection and finish, so that the chosen Session can arrive in a later HTTP request.
41. As a TUI developer, I want terminal-only Commands to implement the shared Command interface, so that they use the normal registry without becoming part of Neuron Interaction.
42. As a TUI developer, I want concurrent Command policy to remain TUI-specific, so that server applications are not coupled to an event-loop exception designed for terminal turns.
43. As a TUI developer, I want concurrent Commands detected by their marker interface, so that the runtime can continue using a simple type check.
44. As a backend developer, I want to choose whether an Agent prompt runs immediately or through a worker, so that the interaction library does not impose an execution topology.
45. As a backend developer, I want to own Agent response streaming, so that SSE, WebSocket, polling or queued processing can evolve independently from interaction state.
46. As a Storage user, I want in-memory and file-backed implementations, so that tests, local tools and simple deployments work without extra infrastructure.
47. As a Storage implementer, I want a shared Storage contract, so that Sessions and Input history can use application-specific persistence.
48. As a library maintainer, I want freedom to define a clean persistence format, so that the extracted package is not constrained by Neuron TUI's legacy serialized documents.
49. As a Neuron TUI maintainer, I want the extraction performed behind existing public behavior before transfer, so that regressions are found while the current test suite remains available.
50. As a package maintainer, I want Neuron Interaction to become a separate repository and dependency, so that terminal and non-terminal Adapters can version it independently.

## Implementation Decisions

- Create the independent Composer library `asterixcapri/neuron-interaction` with root namespace `NeuronInteraction`.
- Expose Command, Session, Input history and Storage as directly composable modules; do not introduce an initial top-level facade.
- Keep Conversation rendering, Agent turn orchestration and Agent response streaming outside Neuron Interaction.
- Define neutral Command identifiers without Adapter syntax. Neuron TUI alone interprets and renders the slash prefix.
- Wrap the raw text following a Command identifier in `CommandArguments`. Structured schemas and generated forms are not part of the first version.
- Define `CommandControls` as the Adapter-provided interface available to ordinary Commands.
- Give `CommandControls` the shared operations `say`, `warn`, `promptAgent`, `requestSelection`, `agent`, `useAgent`, `commands`, `sessions` and `stop`.
- Make `commands` return the `Commands` collection rather than a raw list.
- Keep Command-specific dependencies in Command constructors rather than adding them to `CommandControls`.
- Commands return `void` and do not define semantic Command result classes.
- Have `Commands::run` return one technical `CommandExecution` with completed, unknown and failed states.
- A failed Command execution retains the Command identifier and original exception for Adapter-level reporting or logging.
- Preserve Command mounting order. When identifiers are duplicated, the first mounted Command executes and duplicates may remain visible when enumerated.
- Place `SelectionRequest` and `SelectionOption` in the Command module.
- A `SelectionRequest` carries the target Command, prompt and ordered options but no selected value.
- A `SelectionOption` carries a stable value, visible label and optional description.
- `requestSelection` returns immediately. The Adapter presents the request and invokes the target Command again with the selected value in new `CommandArguments`.
- Do not add a `selected` operation or retain temporary selection state in `CommandControls`.
- Rename the existing `ask` behavior to `promptAgent`. It submits a prompt to the normal Agent flow and returns without receiving the Agent response.
- Do not record prompts generated by `promptAgent` in Input history. Record the original submitted Command invocation instead.
- Move the Session collection, Session value, storage-backed Agent History and native Session Commands into Neuron Interaction.
- Move `ClearCommand`, `ResumeCommand` and the Session Command kit into Neuron Interaction.
- Keep terminal-only Help and Leave Commands in Neuron TUI. They may implement the shared Command interface and receive TUI-specific collaborators through their constructors.
- Keep the concurrent Command marker, limited concurrent controls and concurrency policy in Neuron TUI. Determine concurrency with a type check at the TUI boundary.
- Make resume a two-invocation interaction. Without a key it requests Session selection and finishes; with a key it resumes the Session and installs its History on the answering Agent.
- Derive a Session title in the Session module from the first non-empty user-authored content in its History.
- Keep title escaping, truncation and other rendering rules in each Adapter rather than in the Session module.
- Persist Input history as one ordered sequence per configured Storage, independent from Sessions and shared by all Adapters using that Storage.
- Record both submitted messages and submitted Commands in Input history, subject to the established blank-input and consecutive-duplicate rules.
- Move Input history persistence, sequence behavior and optional recall navigation into Neuron Interaction's `InputHistory`. Keep cursor and draft local to each instance; Adapters own keyboard handling and navigation lifecycle and may navigate independently.
- Move the Storage contract, stored document representation, in-memory implementation and file implementation into Neuron Interaction.
- Do not preserve compatibility with persistence documents written by Neuron TUI. Provide no legacy reader, fallback or automatic migration.
- Refactor the modules behind TUI-independent boundaries on the extraction branch before transferring them to the separate package repository.
- Update Neuron TUI to depend on and adapt Neuron Interaction after the package boundary is stable.

## Testing Decisions

- Test externally observable behavior through public APIs rather than private helpers, internal class layout or exact serialization mechanics.
- Use the public Neuron Interaction API as the primary seam, composing Commands, Sessions, Input history and `InMemoryStorage` with a fake `CommandControls` implementation.
- Through that seam, test neutral Command lookup and dispatch, raw argument delivery, mounting order, duplicate resolution, completed execution, unknown execution and exception capture.
- Through that seam, test the two-invocation selection flow: a first invocation emits a Selection request and a second invocation receives the selected value as Command arguments.
- Through that seam, test that Selection options preserve value, label, description and order without storing a selected value.
- Through that seam, test Session creation, persistence, ordering, title derivation, resume and installation of the resumed History on the answering Agent.
- Through that seam, test that Input history is global across Sessions and Interaction instances sharing Storage, records messages and Commands, ignores blank input, suppresses consecutive duplicates and excludes prompts generated by Commands.
- Test both in-memory and file-backed Storage through their shared observable contract, without asserting a legacy document format.
- Use Neuron TUI's public API with its virtual terminal as the integration seam for the Adapter.
- Through the TUI seam, test slash parsing, Command execution, visible notices and warnings, Agent prompting, selection through the Picker, clear and resume behavior, unknown Commands and survival after Command failure.
- Through the TUI seam, test that ordinary Commands are refused during an active Turn while marker-based concurrent Commands retain their established behavior.
- Reuse the existing Session, Input history, Storage, duplicate Command, Session composition and virtual-terminal tests as prior art, moving assertions to the highest applicable public seam as responsibilities transfer.
- Do not test a Next.js frontend, HTTP framework integration, Symfony Messenger transport or Agent stream protocol as part of this extraction.

## Out of Scope

- Authentication, authorization, tenancy and user identity.
- A built-in HTTP API, Symfony bundle, controller layer or Next.js frontend.
- Agent response events, streaming transports and Conversation rendering.
- Durable jobs, Symfony Messenger workers and Amp Parallel execution policy.
- Subagent creation, scheduling, communication or orchestration.
- Structured Command argument schemas, validation-generated forms and frontend form generation.
- TUI-specific concurrent Command types or controls in the shared package.
- Help, Leave, theme switching and other Adapter-specific Commands in Neuron Interaction.
- Input history cursor, draft and older/newer navigation state shared between Adapters.
- Compatibility with, discovery of or migration from legacy Neuron TUI persistence documents.
- A mandatory all-in-one runtime or facade around the extracted modules.

## Further Notes

- Neuron Interaction coordinates interaction state and Command capabilities; it
  is not an Agent runtime. A backend may execute `promptAgent` immediately or
  dispatch it to infrastructure such as Symfony Messenger.
- A web Adapter may serialize a Selection request in one response and accept
  its selected value in a later request. A TUI Adapter may display the same
  request as a Picker and route its result back through the same Command
  dispatcher.
- The extraction intentionally permits a new persistence representation. Old
  files should not be deleted automatically; they are simply outside the new
  package's compatibility contract.
- The architecture leaves room for a later subagent package or server-side
  orchestration layer without coupling that concern to Sessions, Commands or
  Input history now.
