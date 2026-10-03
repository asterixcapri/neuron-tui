# Neuron TUI

Shared language for an interactive terminal conversation with a Neuron AI
Agent.

## Language

**Neuron TUI**:
The reusable terminal Adapter through which a person converses with an Agent
configured by a Host Application. It manages the active Conversation.
_Avoid_: Neuron CLI, executable, command

**Agent**:
A ready-to-use Neuron AI agent whose capabilities and dependencies have
already been configured by the Host Application. It may coordinate other
Agents, but Neuron TUI sees only the Agent it converses with.
_Avoid_: Bot, model

**Host Application**:
The application that configures the Agent and terminal interaction, and starts
the terminal Adapter. It may supply its own session persistence and interaction
dependencies.
_Avoid_: Neuron TUI, library

**Conversation TUI**:
The interactive terminal interface through which a person converses with an
Agent.
_Avoid_: Command, CLI application

**History**:
The sequence of messages owned by the Agent and represented by the TUI,
including messages that predate the TUI startup. In the Conversation TUI, it is
the active context of the current Session.
_Avoid_: Transcript, TUI log

**Input history**:
The sequence of earlier submitted inputs that a person can recall for editing
or resubmission across Sessions and interaction Adapters. It is independent
from the Agent's History.
_Avoid_: History, conversation history, prompt history

**Storage**:
The collection of namespaced JSON documents in which interaction state may
outlive the Adapter using it. Documents are identified by logical keys.
_Avoid_: Blob store, filesystem, database

**Command**:
A named operation selected explicitly by a person rather than by the model.
Its slash identifier names the operation independently of the presentation used
by the Host Application.
_Avoid_: Message, prompt, action

**Commands**:
The ordered catalog of Commands with distinct identifiers.
_Avoid_: Command container, dispatcher

**Command arguments**:
The opaque text supplied with a Command invocation.
_Avoid_: Parameters, payload

**Command context**:
The shared interaction state and operations available to a Command for one
invocation, including Agent and Session changes and requests for presentation
or Agent responses.
_Avoid_: Command Adapter, Command controls

**Notification**:
Feedback addressed to the person, with an informational, warning or error level.
_Avoid_: Agent message, execution result

**Selection request**:
A presentation-neutral request to choose one value from an ordered list.
It identifies the Command to receive the value in a later invocation and retains
no selected value or continuation.
_Avoid_: Picker, selected value, Command result

**Selection option**:
One opaque value offered by a Selection request, with a label and optional description.
_Avoid_: Choice option, Picker row, menu item

**Exit request**:
A request for the Host Application to leave its interface.
_Avoid_: Response stop, Conversation termination

**Command admission**:
The Host Application's decision whether a Command may run in the current interaction.
_Avoid_: Concurrent command, authorization marker

**Session**:
One conversation owned by a user and identified by a key, with its saved messages
and metadata, that can outlive the TUI process and be reopened by another Agent.
Its title and last-used time help the person recognize it.
_Avoid_: Chat, thread

**SessionStore**:
The user's collection of Sessions, within which conversations are created,
found and selected for resuming. A Store's owner is supplied by the Host
Application.
_Avoid_: Sessions, Session provider, repository, archive

**Picker**:
The state the Conversation TUI is in while a person is choosing from a list
rather than writing to the Agent. Sessions are one of the things chosen this
way, not the only one. Writing the name of a Command is not this state,
however much of a list is on screen meanwhile.
_Avoid_: Menu, popup, dialog, Session picker

**Command suggestions**:
The mounted commands the composer shows while a person is writing a name after
a slash, each under the line that describes it. The selected name can be
completed for further writing or taken immediately; nothing is suspended, so
this is not the Picker, whatever the two look like.
_Avoid_: Picker, menu, autocomplete, palette, command palette

**Conversation**:
The active interaction with an Agent in a selected Session, through which submitted
messages and Commands produce a shared stream of response and interaction events.
Pending input and turn scheduling belong to the client.
_Avoid_: TUI, event loop, Agent

**Pending messages**:
Original inputs held by the TUI until it submits them for execution. They are
not yet prepared or saved in the Session.
_Avoid_: Saved History, durable queue

**Response stop**:
A request to interrupt the model's streamed response when its HTTP callback
consumes the signal. It does not promise cancellation of tools or the entire
Turn.
_Avoid_: Tool cancellation, client close

**Turn**:
One stretch of the conversation, from the moment a person's message is taken
for the Agent to the moment the Agent has finished answering it. A message
written while a turn is under way waits behind it.
_Avoid_: Round, exchange, request

**Working indicator**:
The animated line in the History that tells a person the Agent is still busy,
counting the seconds the turn has taken so far.
_Avoid_: Spinner, loader, progress bar
