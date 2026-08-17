# Provide a reusable Conversation TUI for Neuron AI Agents

Status: ready-for-agent

## Problem Statement

Developers building Neuron AI Agents need a quick, reusable way to converse
with and observe a configured Agent from an interactive terminal. The existing
playground demonstrates the desired experience, but its TUI is coupled to one
project and cannot be installed as a general-purpose PHP library.

Developers should not have to copy the playground, rebuild terminal rendering,
or move provider and Agent configuration into a CLI package. They need a small
module that accepts their existing Agent and handles the complete interactive
terminal experience.

## Solution

Publish Neuron CLI as an embeddable PHP library. A Host Application supplies a
ready-to-use Agent, optionally customizes the displayed title and subtitle,
and runs Neuron CLI.

Neuron CLI presents the Agent's existing History, accepts text messages,
streams Markdown responses, displays compact tool activity, and keeps the
terminal responsive while the Agent works. It preserves the visual language
of the existing playground while replacing project-specific branding and
mixed-language labels with generic English defaults.

The library owns only the Conversation TUI. The Host Application continues to
own Agent construction, providers, credentials, tools, History persistence,
and the executable or framework command used to launch the interaction.

## User Stories

1. As a Neuron AI developer, I want to install a reusable Conversation TUI, so
   that I do not have to copy terminal code between Agent projects.
2. As a Host Application developer, I want to supply an already configured
   Agent, so that provider and domain configuration remain under my control.
3. As a Host Application developer, I want to start the Conversation TUI with
   one method call, so that integration remains small and easy to understand.
4. As a Host Application developer, I want to choose how the interaction is
   launched, so that I can use a script, framework command, or another process
   entry point.
5. As a Host Application developer, I want generic Neuron AI branding by
   default, so that the TUI works without mandatory configuration.
6. As a Host Application developer, I want to customize the title, so that the
   terminal can identify the specific Agent or product.
7. As a Host Application developer, I want to customize the subtitle, so that
   the terminal can provide concise context for the interaction.
8. As a terminal user, I want to see the Agent's existing History when the TUI
   opens, so that prior conversational context is not invisible.
9. As a terminal user, I want the initial view positioned at the newest
   message, so that I can continue the conversation immediately.
10. As a terminal user, I want historical user messages to be visually
    distinct from Agent messages, so that the conversation is easy to follow.
11. As a terminal user, I want system messages hidden, so that operational
    instructions are not presented as conversational turns.
12. As a terminal user, I want Agent reasoning hidden, so that incomplete or
    sensitive reasoning is not exposed.
13. As a terminal user, I want non-text historical content represented by safe
    placeholders, so that raw binary or Base64 payloads never flood the
    terminal.
14. As a terminal user, I want to compose a text message over multiple lines,
    so that longer instructions remain readable before submission.
15. As a terminal user, I want blank submissions ignored, so that accidental
    key presses do not invoke the Agent.
16. As a terminal user, I want Enter to submit my message, so that sending is
    immediate and familiar.
17. As a terminal user, I want Shift+Enter to insert a line break, so that I
    can write structured prompts.
18. As a terminal user, I want Escape to clear my unsent draft without closing
    the TUI, so that an accidental Escape does not terminate the interaction.
19. As a terminal user, I want my submitted message shown immediately, so that
    I can confirm what was sent.
20. As a terminal user, I want only one Agent request active at a time, so that
    responses and tool activity cannot become interleaved ambiguously.
21. As a terminal user, I want the composer unavailable while the Agent is
    responding, so that I cannot accidentally queue unsupported concurrent
    requests.
22. As a terminal user, I want a visible working animation, so that I know the
    Agent is still processing.
23. As a terminal user, I want response text displayed incrementally, so that
    I can begin reading before inference completes.
24. As a terminal user, I want Markdown formatting preserved, so that headings,
    lists, emphasis, tables, links, and code remain readable.
25. As a developer observing an Agent, I want each tool call displayed when it
    begins, so that I understand what the Agent is doing.
26. As a developer observing an Agent, I want to see a compact preview of tool
    arguments, so that I can diagnose behavior without opening separate logs.
27. As a developer observing an Agent, I want the tool activity updated with a
    compact result preview, so that I can connect an invocation to its outcome.
28. As a terminal user, I want tool payload previews sanitized and truncated,
    so that control bytes, invalid text, and very large results cannot damage
    the display.
29. As a terminal user, I want an explicit empty-response indicator, so that a
    completed stream is not mistaken for a frozen request.
30. As a terminal user, I want PageUp and PageDown to browse a long
    conversation, so that earlier turns remain accessible.
31. As a terminal user, I want automatic following while viewing the latest
    content, so that streamed responses stay visible.
32. As a terminal user, I want automatic following paused after I scroll up,
    so that incoming chunks do not immediately discard my reading position.
33. As a terminal user, I want automatic following resumed when I return to
    the bottom, so that I can continue watching the live response.
34. As a terminal user, I want `/exit` interpreted locally, so that I can close
    the TUI without invoking the Agent.
35. As a terminal user, I want unknown Slash commands rejected locally, so that
    the reserved command namespace remains predictable.
36. As a terminal user, I want an unknown Slash command left in the composer,
    so that I can correct it without retyping it.
37. As a terminal user, I want Ctrl+C to close the entire TUI, so that the
    standard terminal exit gesture works.
38. As a developer observing an Agent, I want Agent failures identified by
    exception class and message, so that I have useful diagnostic information.
39. As a terminal user, I want stack traces omitted from the Conversation TUI,
    so that an error does not overwhelm the conversation.
40. As a terminal user, I want the composer restored after an Agent error, so
    that I can continue investigating the Agent.
41. As a Host Application developer, I want Neuron CLI to leave History
    untouched during error handling, so that the library never deletes,
    rewrites, or fabricates persisted conversation data.
42. As a Host Application developer, I want terminal initialization failures
    returned to my application, so that failures that prevent startup can be
    handled outside the TUI.
43. As a developer using human-in-the-loop middleware, I want an explicit
    unsupported-interruption error, so that a paused workflow is not
    misrepresented as an ordinary Agent answer.
44. As a library maintainer, I want all user-facing and repository
    documentation in English, so that the package is consistent for public
    distribution.
45. As a library maintainer, I want deterministic automated tests, so that
    changes can be verified without credentials or network access.

## Implementation Decisions

- The Composer package is named `asterixcapri/neuron-cli`, uses the
  `NeuronCli` PHP namespace, is distributed as a library, and uses the MIT
  license.
- Neuron CLI is the only public module required by the first version. Its
  interface accepts an Agent, optional title and subtitle strings, and an
  optional Symfony terminal implementation, and exposes `run()` to start the
  Conversation TUI.
- The public class is named `NeuronCli`. It contains the behavior represented
  by `ChatTui` in the existing playground; `ChatTui` itself is not a second
  public abstraction.
- The interface accepts the concrete Neuron AI `Agent` base class. The current
  Neuron AI Agent interface does not expose History, which Neuron CLI needs in
  order to render pre-existing messages.
- The Host Application supplies a fully configured Agent. Neuron CLI does not
  configure providers, models, credentials, tools, HTTP clients, or History
  persistence.
- Neuron CLI does not provide or register an executable or a Symfony Console
  command. Launch integration belongs to the Host Application.
- The default title and subtitle use generic Neuron AI language. Only these
  two pieces of display identity are configurable in the first version.
- User-facing labels, statuses, empty states, errors, and documentation are in
  English. Localization is not introduced.
- Layout and styling follow the playground's header, transcript, composer,
  status bar, magenta/cyan/red palette, speaker glyphs, animated working
  indicator, and composer height of one to five visible lines.
- History remains owned by the Agent. Neuron CLI reads and renders every
  message exposed by the configured History, including messages that predate
  startup, but does not impose its own pruning policy.
- Historical user and assistant text is rendered as conversation content.
  Historical tool calls and results use the same compact activity presentation
  as live tool events.
- System messages and reasoning content are not rendered.
- The first version accepts text input only. Historical image, file, audio, or
  video content is represented by a short type placeholder, optionally
  including a safe filename where available; raw payloads are never rendered.
- Agent responses are consumed through Neuron AI streaming. Text chunks update
  one accumulating Markdown view, while tool call and result chunks update
  their corresponding activity views.
- Markdown rendering uses Symfony TUI's Markdown widget. CommonMark and
  Tempest Highlight are direct runtime dependencies because that widget needs
  them at runtime.
- Agent response processing runs as an Amp task integrated with the Revolt
  event loop used by Symfony TUI. Amp is therefore a direct runtime dependency.
- Interaction is single-flight. The composer loses focus while a response is
  active and regains focus after completion or a handled Agent failure.
- Tool activity shows the tool name, a sanitized single-line argument preview,
  and a sanitized single-line result preview. Preview content is truncated to
  a bounded display width.
- Scrolling uses PageUp and PageDown. Live following occurs only while the user
  remains at the bottom; scrolling upward pauses forced following.
- `/exit` is the only Slash command in the first version. Slash dispatch is
  kept local and cohesive so that a command registry can replace or extend it
  later without being exposed prematurely.
- Unknown Slash commands are not sent to the Agent and remain available for
  correction.
- Ctrl+C closes the TUI. Escape clears the unsent composer text instead of
  closing the TUI.
- Agent exceptions are handled inside the Conversation TUI by displaying the
  exception class and message and restoring the composer. Stack traces are
  omitted.
- Error handling never snapshots, flushes, reconstructs, or appends synthetic
  messages to History. This deliberately removes the playground's recovery
  behavior because persisted History belongs to the Agent and Host
  Application.
- Workflow interruptions are not resumed in the first version. Human-in-the-
  loop interruptions receive an explicit unsupported message and the composer
  is restored.
- Terminal startup failures propagate to the Host Application. The first
  version requires an interactive TTY and has no plain-text fallback.
- The normal documented usage creates Neuron CLI and calls `run()`. No extra
  lifecycle abstraction or guard for repeated `run()` calls is introduced.
- The supported baseline is PHP `^8.4.1`, Neuron AI `^3.15.26`, and Symfony
  TUI `^8.1`. Symfony TUI minor updates are accepted by Composer and must be
  protected by the automated test suite.
- Runtime dependencies are limited to Amp, CommonMark, Neuron AI, Symfony TUI,
  and Tempest Highlight. Symfony Console, Dotenv, provider SDKs, and project-
  specific HTTP clients are not direct dependencies.
- The implementation should remain a direct extraction with the minimum
  internal structure needed for locality. No builder, facade, configuration
  object, adapter hierarchy, or speculative extension seam is introduced.

## Testing Decisions

- The highest and only feature seam under automated test is the public Neuron
  CLI module. Tests construct it, run it, drive terminal input, and assert on
  externally observable terminal output and Agent History.
- Symfony TUI's virtual terminal is the terminal adapter used by tests. It
  captures rendered output and simulates key input without requiring a
  physical TTY.
- Neuron AI's fake provider is the provider adapter used by tests. It supplies
  deterministic streamed text, tool calls, tool results, empty responses, and
  failures without credentials or network access.
- Tests use a real Neuron AI Agent with the fake provider rather than mocking
  Neuron CLI internals.
- Good tests describe terminal-user behavior and survive refactoring of widget
  composition, event handling, and helper methods. Internal classes, private
  methods, style declarations, and intermediate state are not independent test
  surfaces.
- The existing playground chat tests are prior art for driving a virtual
  terminal through the Revolt event loop, inspecting ANSI-stripped output,
  verifying incremental Markdown, and exercising fake tool activity.
- Automated coverage includes generic and customized branding, pre-existing
  History, hidden system and reasoning content, safe non-text placeholders,
  multiline text submission, blank submission, incremental Markdown, tool
  previews, empty responses, single-flight input, keyboard exit behavior,
  unknown Slash commands, sticky scrolling, Agent errors, unchanged History
  ownership, and unsupported workflow interruptions.
- The test suite makes no live provider calls, requires no API keys, and
  performs no network requests.
- A real-provider smoke test may be documented for manual use, but it is not
  part of the automated suite.

## Out of Scope

- Agent construction or configuration.
- Provider, model, credential, HTTP client, Tool, or History configuration.
- A bundled executable or integration with Neuron AI's existing executable.
- A Symfony Console command or framework-specific registration.
- Custom Slash commands, `addCommand()`, a command registry, aliases, command
  help, or Slack commands.
- Human-in-the-loop approval, rejection, editing, or workflow resume.
- Concurrent requests, queued messages, or cancellation of one active Agent
  response while keeping the TUI open.
- Multimodal composer input, uploads, clipboard attachments, or inline media
  rendering.
- Display of system messages, provider reasoning, or chain-of-thought.
- Full tool payload viewers, expandable activity details, or unbounded tool
  output.
- Configurable layout, colors, styles, glyphs, keyboard bindings, or
  localization.
- A non-interactive or plain-text fallback.
- Stack trace rendering or a logging subsystem.
- A Neuron AI abstraction introduced solely to replace the concrete Agent
  dependency.
- Special handling for reuse of the same Neuron CLI instance across multiple
  calls to `run()`.
- Live API integration tests.

## Further Notes

- The reference playground is the source of the initial interaction and visual
  design. Project-specific WikiAgent construction and Symfony Console wiring
  are references only and are not responsibilities of Neuron CLI.
- Future custom Slash commands are expected to fit an optional chainable
  interface such as `addCommand()`, but that interface must be designed from
  concrete command requirements in a later spec rather than anticipated now.
- Symfony TUI is currently experimental. Accepting compatible minor releases
  makes the public-seam regression suite especially important.
