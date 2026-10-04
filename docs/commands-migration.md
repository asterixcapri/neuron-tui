# Unified Commands migration

Tui constructs Conversation with the Agent, initial Session, ConfigurationStore,
StopSignal and message processors, then supplies the configured Commands through
Conversation::setCommands(). Configure these before run. Registry construction
is variadic: `new Commands($first, $second)`.
Commands::addCommand() extends a registry; later Commands replace earlier ones
with the same name. Invalid names fail. Tui passes its registry to
Conversation::setCommands() after construction.
Commands omitted means no commands, including no automatic help or exit.

Command run methods now receive concrete CommandContext and return void. They use
notify with NotificationLevel, requestSelection with SelectionRequest, requestExit,
and promptAgent with UserMessage. State controls and stores remain available.
commands() returns a consultation list. Host adapters and generic completion
results are removed; the TUI consumes Conversation::sendInput streams.

The five interaction events are Notification, SelectionRequest, ExitRequest,
SessionChanged and AgentChanged. Other objects remain native Neuron events.
Requests execute in registration order. Command prompts execute in the current
stream, rather than entering the human FIFO, and have no live human preview.
The Agent and persisted History retain expanded messages; forDisplay projects
saved History. Message processors run once per message.

The picker presents ordered labels and descriptions, then submits CommandInput
with command and opaque value. Escape closes it and submits nothing. Conversation
keeps no pending selection; another HTTP host can submit the same values in a new
request after restoring stores and Session. A command owns argument validation.

The terminal admits HelpCommand and LeaveCommand while busy and refuses ordinary
commands, including selection responses. This is host policy, shared with command
suggestions, rather than a library concurrency marker. Commands outside those
classes are ordinary even if they use familiar names. Human inputs still follow
the FIFO, with preparation at their scheduled turn. Response stop, title scheduling
and Amp task ownership remain in the TUI.

Unknown names and admission refusal appear as Error and Warning notifications.
Command exceptions preserve applied state and execute registered requests before
propagating the original exception. Request execution errors immediately interrupt
the remaining sequence. The TUI presents errors in its stream catch. No completion
hook, rollback, retry or failure event is added. Normal prompt return, including
approval or response stop, does not impose a core policy on later requests.

ExitRequest asks the terminal to leave. Other hosts may ignore it; it does not end
Conversation or stop Agent work. State events synchronize terminal History and
indicators. Already started responses retain their captured Agent and Session.

Local development uses sibling neuron-tui and neuron-interaction checkouts. Path
repository versions are explicitly 1.x-dev so feature branch names do not change
the Composer package contract. CI pins the compatible core commit and checks it
out alongside this package before installing dependencies. The library keeps its
local composer.lock ignored; applications retain their own lock files.
The examples use the same sibling layout and can run `composer stan` and
`composer test` independently after `composer install`.
