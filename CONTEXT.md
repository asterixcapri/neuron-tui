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
already been configured by the Host Application. It may coordinate Subagents,
but only the Agent in charge answers the conversation.
_Avoid_: Bot, model

**Subagent**:
An Agent created to carry work in a separate History while another Agent
remains in charge of the conversation. Its stable identity lets that Agent
continue the exchange across more than one Turn.
_Avoid_: Delegation, worker, tool

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
including messages that predate the TUI startup.
_Avoid_: Transcript, TUI log

**Input history**:
The sequence of earlier composer inputs that a person can recall for editing
or resubmission. It is TUI state rather than part of the Agent's History.
_Avoid_: History, conversation history, prompt history

**Storage**:
The collection of namespaced JSON documents in which TUI-owned state may
outlive the module using it. Documents are identified by logical keys.
_Avoid_: Blob store, filesystem, database

**Command**:
Input beginning with `/` whose effect the TUI decides rather than the model.
What it does is code someone wrote, and that code is free to send the Agent
a prompt of its own.
_Avoid_: Message, prompt, action

**Concurrent command**:
A Command whose synchronous run may overlap a Turn. It receives only
Concurrent Controls, so it cannot reach the Agent, put a prompt to it, open a
Picker or replace the conversation while an answer is on its way.
_Avoid_: Async command, background command, command that runs while working

**Controls**:
What a Command may do while it runs: say something in the conversation,
put a prompt to the Agent, offer a Picker, reach the Agent itself, put another
Agent in charge of answering, reach the Sessions, list the mounted commands,
leave the terminal. A Concurrent command instead receives Concurrent Controls:
saying, warning, listing and leaving, the operations whose meaning remains
stable while an answer is on its way.
_Avoid_: Context, facade, API

**Concurrent Controls**:
What a Concurrent command may do while its synchronous run overlaps a Turn:
say, warn, list the mounted commands and leave the terminal.
_Avoid_: Limited Controls, async controls, background controls

**Command kit**:
A group of Commands mounted in one go, carrying between them whatever
they need to work. A Conversation TUI mounts nothing on its own, so a kit is
the short way for a Host Application to say yes to several commands at once,
and it can be taken with some of them left out.
_Avoid_: Toolkit, bundle, plugin, pack

**Session**:
One conversation, identified by a key and held by a single History, that
may outlive the TUI process and can be reopened. No Agent owns it: any Agent
can be handed it and carry it on. Its title and last-used time identify it to
a person; its storage may also report its size.
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
One stretch of the conversation, from the moment an input is taken for the
Agent to the moment the Agent has finished answering it. An input may come
from the person or a Subagent; another input arriving while a turn is under
way waits behind it.
_Avoid_: Round, exchange, request

**Working indicator**:
The animated line in the History that tells a person the Agent is still busy,
counting the seconds the turn has taken so far.
_Avoid_: Spinner, loader, progress bar
