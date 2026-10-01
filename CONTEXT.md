# Neuron TUI

Shared language for an interactive terminal conversation with a Neuron AI
Agent.

## Language

**Neuron TUI**:
The reusable terminal Adapter through which a person converses with an Agent
configured by a Host Application through its conversation runtime.
_Avoid_: Neuron CLI, executable, command

**Agent**:
A ready-to-use Neuron AI agent whose capabilities and dependencies have
already been configured by the Host Application. It may coordinate other
Agents, but Neuron TUI sees only the Agent it converses with.
_Avoid_: Bot, model

**Host Application**:
The application that configures the Agent, composes the conversation runtime
and starts the interaction.
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
A named operation whose effect an interaction Adapter decides rather than the
model. Its identifier includes the shared slash convention, such as `/help`;
each Adapter decides how a person submits it.
_Avoid_: Message, prompt, action

**Commands**:
The ordered collection of mounted Commands.
It resolves an identifier to the first matching Command and coordinates its
execution through a Command Adapter.
_Avoid_: Command container, command list

**Command execution**:
The technical outcome of a Command dispatch: completed, unknown or failed.
It does not describe the domain effect or presentation of a Command, or imply
that a requested selection or Agent response has finished.
_Avoid_: Command result, response, view model

**Command arguments**:
The text supplied with a Command invocation after an interaction Adapter has
removed its presentation syntax.
_Avoid_: Parameters, payload

**Selection request**:
A presentation-neutral request for a person to choose one value from a list.
The Adapter presents it and invokes the named Command again with the selected
value as Command arguments; the request itself retains no selection.
_Avoid_: Picker, selected value, Command result

**Selection option**:
One value offered by a Selection request, carrying the label shown to a person
and, when useful, a description. Its value is returned unchanged as Command
arguments after the person selects it.
_Avoid_: Choice option, Picker row, menu item

**Concurrent command**:
A Command declared safe to execute while an Agent is working, without
interfering with the state used by that work. The core runtime permits only
Concurrent Commands while busy; the interaction Adapter may further restrict
admission and presents refusal.
_Avoid_: Async command, background command, command that runs while working

**Command controls**:
The presentation-independent verbs and shared interaction state available to
a Command for one execution. They cover notices, warnings, Agent prompts,
selections, the answering Agent, mounted Commands, SessionStore, and leaving the
interaction.
_Avoid_: Command context, environment, facade, API

**Command Adapter**:
The realization of Command controls in a particular interaction environment.
It presents shared runtime admission and may impose additional restrictions,
carries out Command operations, and interprets
their technical outcomes as terminal effects, backend responses, or other
output appropriate to that environment.
_Avoid_: Command runner, Command result

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

**Conversation runtime**:
The conversation coordinator shared by interaction environments. It holds the
current Agent and Session, accepts messages and executes one Turn at a time.
_Avoid_: TUI, event loop, Agent

**Pending messages**:
Accepted messages waiting in order behind the current Turn. They are retained
only for the lifetime of the conversation runtime.
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

Command availability is shared core policy: client-owned Commands preserve first-match identifiers, and ConversationRuntime enforces idle/concurrent admission for invocation. Terminal suggestions use that same policy; the terminal Adapter presents refusal and all Command effects. Selection continuation invokes through the runtime again against current state.
