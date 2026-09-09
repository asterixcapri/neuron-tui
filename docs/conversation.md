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
the target Command with that value as `CommandArguments`. The View does not
depend on the shared Selection option type.
