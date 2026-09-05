# Neuron TUI

Shared language for an interactive terminal conversation with a Neuron AI
Agent.

## Language

**Neuron TUI**:
The reusable terminal Adapter through which a person converses with an Agent
supplied by a Host Application.
_Avoid_: Neuron CLI, executable, command

**Agent**:
A ready-to-use Neuron AI agent whose capabilities and dependencies have
already been configured by the Host Application. It may coordinate other
Agents, but Neuron TUI sees only the Agent it converses with.
_Avoid_: Bot, model

**Host Application**:
The application that configures the Agent and starts the terminal
interaction.
_Avoid_: Neuron TUI, library

**Conversation TUI**:
The interactive terminal interface through which a person converses with an
Agent.
_Avoid_: Command, CLI application

**History**:
The sequence of messages owned by the Agent and represented by the TUI,
including messages that predate the TUI startup. A History may exist independently
of Sessions and have its own persistence.
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
The ordered collection of mounted Commands, including those supplied by kits.
It resolves an identifier to the first matching Command and reports its
Command execution.
_Avoid_: Command container, command list

**Command execution**:
The technical outcome reported by the Command dispatcher: completed, unknown
or failed. It does not describe the domain effect or presentation of a
Command.
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
A Command the TUI permits to run while a Turn is active. This permission is
reserved for Help and Leave and is a TUI policy, not a separate Command type.
_Avoid_: Async command, background command, command that runs while working

**Command controls**:
The presentation-independent verbs and shared interaction state available to
a Command for one execution. They let it say or warn, put a prompt to the
Agent through `promptAgent()`, request a selection, inspect or replace the
answering Agent, use the mounted Commands and Sessions, and stop the
interaction. Command-specific dependencies still arrive through the Command's
constructor.
_Avoid_: Command context, environment, facade, API

**Command kit**:
A group of Commands mounted in one go, carrying between them whatever
they need to work. A Conversation TUI mounts nothing on its own, so a kit is
the short way for a Host Application to say yes to several commands at once,
and it can be taken with some of them left out.
_Avoid_: Toolkit, bundle, plugin, pack

**Session**:
One conversation managed within Sessions, identified by a key and held by a
single History, that may outlive the TUI process and can be reopened. No Agent owns it: any Agent
can be handed it and carry it on. Its title and last-used time identify it to
a person; its title comes from the first non-empty user-authored content in
its History, while each Adapter decides how to render it. Its storage may also
report its size.
_Avoid_: Chat, thread

**Sessions**:
The collection within which Session keys are minted and resolved. It is where
a new Session starts and where an existing one is found to be resumed.
_Avoid_: Session provider, repository, archive

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

**Turn**:
One stretch of the conversation, from the moment a person's message is taken
for the Agent to the moment the Agent has finished answering it. A message
written while a turn is under way waits behind it.
_Avoid_: Round, exchange, request

**Working indicator**:
The animated line in the History that tells a person the Agent is still busy,
counting the seconds the turn has taken so far.
_Avoid_: Spinner, loader, progress bar
