# Conversation modules

`ConversationInputHandler` handles submit, draft-change and key events.
`SubmissionParser::parse()` separates a slash-prefixed `CommandInput` from a
`MessageForAgent`, whose `content` retains the original message text. Parsing
does not depend on which Commands are mounted.

`ConversationRuntime::submitMessage()` accepts a message into `TurnQueue`.
A Turn occupies the conversation immediately when its message becomes current,
before execution is scheduled. Later messages wait in submission order.

`TurnQueueState` describes the queue:

- `Idle`: no current Turn.
- `Ready`: a current message awaits execution handoff; the conversation is busy.
- `Running`: the message has been handed over and completion has not yet been
  acknowledged; the conversation remains busy even between asynchronous callbacks.

`takeForExecution()` hands over a message once, without calling the Agent.
`finishAndAdvance()` acknowledges completion and prepares the next waiting
message, or returns to idle. `queuedMessages()` exposes only waiting messages.

The runtime captures the answering Agent when `tick()` schedules
`TurnRunner::run()`. Its `runningTurn` Future remains until the runtime processes
completion. `showTurnStarted()`, `showTurnFinished()` and
`showQueuedMessages()` update presentation; they do not execute the Agent.

## Choice presentation

Neuron Interaction's `SelectionOption` remains the shared Selection option.
`TuiCommandAdapter` converts it into `NeuronTui\View\ChoiceOption`, the View's
presentation value with a key, label and optional detail.
`ConversationView::choose()` validates the options and delegates to `Picker`.
It returns the chosen key unchanged, or null on cancellation. The Adapter resumes
the target Command with that value as a string. The View does not
depend on the shared Selection option type.

## Historical presentation

Commands replace History directly through `adapter->agent()->setChatHistory()`.
`ConversationRuntime::synchronizeHistory()` remembers the History currently
shown and replaces the display only when the Agent holds a different History
object. Startup and Command completion synchronize it; Command notices,
warnings, errors and prompts synchronize before their presentation effects so
those effects belong to the new conversation. Admission does not track History.
An unchanged History keeps its scroll position, transient lines and streaming
state. This comparison detects replacement, not edits inside the same object.

`HistoryProjection` builds a snapshot from the Agent's messages. Its `entries()`
returns `ProjectedEntry` values containing text and a `ProjectedEntryKind`:
`Person`, `Agent` or `Tool`. These are presentation categories; a tool entry
may combine a call and a result from separate messages, or remain pending.
View's `HistoryEntry` instead holds a mutable widget and its measured height.

The projection defines historical presentation rules. It excludes system
messages and reasoning, replaces attachments with placeholders, and prepares
filenames and tool previews for display. Ordinary text is retained without
projection-level sanitization. The Agent owns History, Session metadata remains
independent, and View handles rendering.

`ToolCallCorrelation::registerCall()` records an entry position.
`matchResult()` returns the stored position for an explicit call ID without
consuming that association. Without an ID, it consumes the first waiting
position for the tool name; an unmatched result returns null. Both the
projection and live `ToolActivity` create an entry for an unmatched result.
They share `ToolActivityText` formatting. Historical timing uses
`FALLBACK_DURATION_SECONDS`, still displayed as `<1s`, because measured timing
is unavailable.
