# Spec: A Neuron AI-native interface for Neuron TUI

Status: ready-for-agent

## Problem Statement

As a developer building an Agent or a system of Agents with Neuron AI, I want
to expose that conversation through a reusable terminal interface. The current
package provides the terminal behaviour, but its identity and construction do
not fit naturally into Neuron AI's public API: the package and entry class are
named as a CLI, configuration is concentrated in the constructor, and the
terminal is assembled before incremental configuration can be expressed.

The name also makes the library sound like a complete executable product,
which risks overlapping conceptually with Maestro. The intended responsibility
is narrower: a Host Application owns and configures the Agent, while this
package is the terminal Adapter through which a person converses with it.

## Solution

Rename the package to Neuron TUI and make `NeuronTui\Tui` its final public
entry point. Require a ready-to-use concrete Agent at construction, provide
`make()` as an equivalent and documented construction style, and expose a
small fluent configuration surface for terminal concerns only: title,
subtitle and mounted commands.

The TUI collects configuration until `run()` starts. It then builds the
Conversation TUI once, freezes the instance and conducts the terminal Session.
The Host Application remains the sole owner of provider, instructions, tools,
middleware, History, Sessions and any multi-Agent orchestration. A coordinating
Agent represents a system of Agents at the terminal boundary.

## User Stories

1. As a Neuron AI developer, I want to construct a TUI with a ready-to-use
   Agent, so that I can converse with that Agent in a terminal.
2. As a Neuron AI developer, I want `Tui::make()` to resemble the familiar
   construction style of `Agent::make()`, so that the package feels native to
   the Neuron AI ecosystem.
3. As a PHP developer, I want the public constructor to remain valid, so that
   dependency injection containers and ordinary object construction work
   without a special factory.
4. As a PHP developer, I want `make()` and the public constructor to be
   equivalent, so that choosing one does not change behaviour.
5. As a Host Application author, I want the Agent to be mandatory at
   construction, so that a TUI can never exist in a partially configured
   state waiting for `setAgent()`.
6. As a Host Application author, I want to pass a concrete Neuron AI Agent,
   so that the TUI can stream responses and represent its existing History.
7. As a multi-Agent application author, I want to pass a coordinating Agent,
   so that internal routing and delegation remain invisible to the TUI.
8. As a Host Application author, I want a mounted Slash command to retain the
   ability to put another Agent in charge, so that an interactive conversation
   may switch Agents when the application intends it.
9. As a Host Application author, I want Agent switching to preserve the
   established History behaviour, so that changing the answering Agent does
   not unexpectedly erase the conversation.
10. As a package consumer, I want the Composer package to be named
    `asterixcapri/neuron-tui`, so that its name describes a reusable terminal
    interface rather than a complete CLI product.
11. As a package consumer, I want the namespace to be `NeuronTui`, so that all
    public and internal types share the package's chosen identity.
12. As a package consumer, I want the entry class to be named simply `Tui`, so
    that the namespace supplies the context in the same way that
    `NeuronAI\Agent\Agent` does.
13. As a package consumer, I want every package type to move to the new
    namespace together, so that the library does not expose a mixture of old
    and new identities.
14. As a new package consumer, I want documentation to lead with
    `Tui::make($agent)`, so that the shortest valid example is immediately
    visible.
15. As a Host Application author, I want to set the terminal title fluently,
    so that application branding does not widen the constructor.
16. As a Host Application author, I want to set the terminal subtitle
    fluently, so that I can describe the current Agent or purpose.
17. As a Host Application author, I want title and subtitle setters to return
    the same TUI, so that configuration can be chained before execution.
18. As a Host Application author, I want `Neuron AI` and `Agent conversation`
    as defaults, so that the minimal construction is immediately usable.
19. As a Host Application author, I want title and subtitle strings preserved
    exactly, including empty strings, so that the TUI does not invent
    validation or fallback policy.
20. As a Host Application author, I want to add one command fluently, so that
    simple terminal compositions stay concise.
21. As a Host Application author, I want to add an array of commands in one
    call, so that incremental configuration follows the convenience of
    `Agent::addTool()`.
22. As a Host Application author, I want to add a Command kit through the same
    operation, so that related Slash commands can still be mounted together.
23. As a Host Application author, I want repeated `addCommand()` calls to
    accumulate commands, so that separate application modules can contribute
    terminal behaviour.
24. As a Host Application author, I want commands to retain addition order, so
    that help and Command suggestions remain predictable.
25. As a Host Application author, I want invalid command values rejected when
    they are added, so that configuration errors are reported at their public
    boundary.
26. As a Host Application author, I want duplicate command names accepted, so
    that the TUI does not add uniqueness policy absent from `Agent::addTool()`.
27. As a Host Application author, I want the first command with a duplicate
    name to receive matching input, so that resolution is stable and follows
    Neuron AI's first-match tool behaviour.
28. As a person using the Conversation TUI, I want duplicate mounted commands
    to remain visible in suggestions, so that the implementation does not hide
    entries through additional deduplication logic.
29. As a Host Application author, I want no Slash commands mounted by default,
    so that the application explicitly chooses every local capability.
30. As a person using a TUI without an exit command, I want `Ctrl+C` to remain
    available, so that the terminal can still be closed.
31. As a Host Application author, I want to configure the TUI completely
    before it starts, so that the visible terminal reflects the final title,
    subtitle and command collection.
32. As a Host Application author, I want widgets, listeners, command lookup,
    Turn state and History projection built only when `run()` starts, so that
    fluent changes made after construction are honoured consistently.
33. As a Host Application author, I want configuration changes rejected once
    `run()` begins, so that the live Conversation TUI cannot become partially
    reconfigured.
34. As a Host Application author, I want a TUI instance to run only once, so
    that listeners and terminal state cannot accidentally be reused.
35. As a Host Application author, I want `run()` to block for the terminal
    Session and return nothing, so that its contract describes a UI lifetime
    rather than one Agent Turn.
36. As a Host Application author, I want the Agent to retain ownership of the
    History after the TUI closes, so that the application can continue to use
    the conversation.
37. As a test author, I want to supply a Terminal implementation at
    construction, so that complete terminal interactions remain testable
    without exposing UI internals.
38. As a package consumer, I want the optional Terminal dependency omitted
    from introductory examples, so that infrastructure does not distract from
    the normal API.
39. As a Host Application author, I want to configure provider, instructions,
    tools, middleware and History directly on the Agent, so that there is only
    one owner of Agent behaviour.
40. As a Host Application author, I want Sessions to remain the concern of
    mounted commands and their provider, so that the TUI constructor does not
    regain application storage concerns.
41. As a package consumer, I want the TUI to remain final and composed, so
    that reusable terminal configurations are ordinary Host Application
    factories rather than subclasses with competing hooks.
42. As a package consumer, I want the rename to be complete without aliases or
    a compatibility layer, so that the new major interface contains only one
    supported dialect.
43. As a future web UI author, I want terminal-specific concerns to stay in
    Neuron TUI, so that a later web Adapter can integrate with Agent streaming
    without inheriting from `Tui`.

## Implementation Decisions

- The Composer package is renamed to `asterixcapri/neuron-tui`. Its description,
  autoload configuration, development namespace, documentation and examples
  use the Neuron TUI identity.
- The root namespace becomes `NeuronTui` for all public and internal package
  types. The old namespace and `NeuronCli` entry class are removed without
  aliases, bridge classes or other compatibility support.
- The public entry point is `final class NeuronTui\Tui`. It composes an Agent
  and does not extend Agent or Workflow.
- The public constructor requires a concrete `NeuronAI\Agent\Agent` and accepts
  an optional `TerminalInterface` as its only other argument.
- A concrete Agent is used for this major because the installed Neuron AI
  `AgentInterface` does not expose the History getter required by the
  Conversation TUI.
- `Tui::make()` has the same required Agent and optional Terminal arguments as
  the constructor, returns `self`, and delegates directly to construction.
  Documentation leads with `make()`, while direct construction remains fully
  supported.
- `make()` is implemented locally with a typed signature. The package does not
  depend on Neuron AI's static-constructor trait merely to reproduce this
  convention.
- The only public configuration mutators on `Tui` are `setTitle()`,
  `setSubtitle()` and `addCommand()`. Each returns `self`.
- There is no `setAgent()`, `setTerminal()`, `TuiConfig`, protected
  configuration hook or subclass extension surface.
- Title defaults to `Neuron AI`; subtitle defaults to `Agent conversation`.
  Setters preserve the supplied strings without trimming, rejecting or
  replacing empty values.
- `addCommand()` accepts a `Command`, a Command kit, or an array containing
  either. It checks each item when it is added, accumulates across calls and
  preserves insertion order. A kit contributes its commands at the position
  where the kit is added.
- Duplicate command names are valid. Input resolution reaches the first
  matching command. Command suggestions retain ordered duplicate entries.
  This rule supersedes only the duplicate-name rejection recorded in ADR 0002;
  all other ADR 0002 decisions remain in force.
- The Conversation TUI mounts no commands of its own. Shipped commands remain
  ordinary commands selected and added by the Host Application. `Ctrl+C`
  remains the command-independent way to leave.
- Construction stores dependencies and configuration but does not assemble
  the live Conversation TUI. View, Working indicator, command resolution,
  suggestions, listeners, Turn queue, Agent Turn and initial History
  projection are resolved lazily when `run()` begins.
- Configuration is mutable only before `run()`. A single started-state guard
  freezes all mutators at the start of `run()` and prevents a second run of the
  same instance. Violations are logic errors.
- `run()` retains its blocking `void` contract and its interactive-terminal
  checks. The Agent, not the TUI, owns the History before, during and after the
  terminal Session.
- The Agent passed at construction is the initial answering Agent. Existing
  `Controls::useAgent()` behaviour remains available to mounted commands,
  including its established handling of History.
- Agent configuration is never delegated through `Tui`. Provider,
  instructions, tools, middleware, persistence and History remain configured
  through Neuron AI. Session providers remain dependencies of commands that
  use them.
- A multi-Agent system is supplied as a top-level or coordinating concrete
  Agent. Arbitrary Workflow values are not accepted because Workflow does not
  guarantee conversational messages, streaming semantics or History.
- A future web interface does not derive from `Tui`, and this change does not
  introduce a shared UI abstraction. Both channels may consume Agent events;
  a common port should be extracted only after a second concrete Adapter makes
  its required contract known.
- The complete rename and fluent-interface change are one intentional breaking
  change. No migration layer is included.

## Testing Decisions

- The authoritative seam is the public `NeuronTui\Tui` interaction using the
  controllable Terminal implementation already used by the Conversation TUI
  integration tests. Tests supply input and observe rendered output, command
  effects, Agent History and public exceptions rather than inspecting widget
  trees, listener collections or private state.
- Existing complete `NeuronCli` interaction tests are the prior art. They move
  to the new namespace and entry point, preserving coverage of History,
  streaming, Turns, Slash commands, Command kits, Controls, Sessions, Picker,
  Command suggestions, terminal shutdown and failure handling.
- Construction tests cover the documented `make()` form, direct construction,
  equivalent arguments and equivalent observable behaviour.
- Minimal-use tests cover default title and subtitle, an Agent's existing
  History, no automatically mounted commands and leaving through `Ctrl+C`.
- Fluent branding tests cover title and subtitle changes made after
  construction but before `run()`, including deliberately empty values, and
  observe the resulting terminal rather than stored configuration.
- Command tests cover adding one command, an array, a Command kit and multiple
  successive calls. They verify insertion order through public help,
  suggestion or execution behaviour.
- Validation tests pass an invalid value within an added array and verify that
  the error arises from `addCommand()` rather than later terminal startup.
- Duplicate-name tests verify both required external behaviours: the first
  matching command executes, while all duplicate entries remain available to
  Command suggestions in their addition order.
- Lifecycle tests configure successfully before startup, verify that each
  mutator fails after startup has begun, and verify that a completed instance
  cannot be run again.
- Lazy-bootstrap tests demonstrate externally that all pre-run configuration
  is reflected together when the terminal starts. They do not assert the time
  at which individual private objects are allocated.
- Agent-ownership tests verify that provider, tools and History configured on
  the Agent remain effective, and that the Agent retains the resulting History
  when `run()` returns.
- Agent-switching tests preserve the existing public command interaction in
  which `Controls::useAgent()` puts another Agent in charge and carries the
  conversation according to the established contract.
- Multi-Agent support needs no synthetic Workflow test. A coordinator is an
  ordinary Agent at this boundary, so the standard Agent interaction is the
  behavioural contract.
- Terminal dependency tests continue to use the optional constructor seam.
  The default Terminal still rejects non-interactive input, and startup
  failures still propagate to the Host Application.
- Package-level validation covers Composer metadata, PSR-4 autoloading, the
  absence of old namespace references in shipped code and documentation, and
  successful static analysis and full test execution after the rename.
- Tests assert only public behaviour: terminal content, input handling,
  command effects, History, fluent identity and documented failures. They do
  not lock down private bootstrap methods, the chosen started-state
  representation or internal collection types.

## Out of Scope

- Reproducing Maestro's user interface, styling, tool views, approval modes or
  product-level behaviour.
- Turning Neuron TUI into an executable application that chooses providers,
  models, tools or Agents for the user.
- Accepting arbitrary Workflow instances as conversations.
- Introducing a local conversational interface to replace the concrete Agent
  in this major.
- Adding a web UI, a web framework integration, a streaming protocol Adapter
  or a speculative shared terminal/web abstraction.
- Adding provider, instruction, tool, middleware, persistence, History or
  Session configuration methods to `Tui`.
- Adding `setAgent()`, `setTerminal()`, protected configuration hooks,
  subclass-based TUI configuration or a `TuiConfig` object.
- Mounting Help, Leave, Clear, Sessions or any other Slash command by default.
- Rejecting, hiding, renaming or otherwise normalising duplicate command
  names beyond the specified first-match behaviour.
- Adding extra lifecycle abstractions beyond the single-use started-state
  guard required to keep configuration coherent.
- Providing old package, namespace or class compatibility.
- Redesigning the Conversation TUI's visual appearance or changing existing
  Turn, Picker, Session, History or Working indicator behaviour except where
  necessary to expose the new public construction interface.

## Further Notes

- ADR 0003 is the controlling architectural decision for the public TUI
  interface. ADR 0002 continues to control command ownership, except for its
  superseded duplicate-name rule.
- The API vocabulary intentionally follows Neuron AI where semantics match:
  `make` constructs, `set` replaces a terminal value and `add` accumulates
  commands. Neuron AI's Agent subclass hooks are deliberately not copied.
- Neuron TUI and Maestro do not overlap in responsibility. Neuron TUI is an
  Adapter around an Agent supplied by a Host Application; Maestro is a complete
  Agent product that owns substantially more application policy.
- The name `Tui` intentionally mirrors `Agent`: the namespace supplies the
  package context, while the class names its central object. Internal Symfony
  TUI imports may be locally aliased where necessary.
