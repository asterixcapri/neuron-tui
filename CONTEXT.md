# Neuron CLI

Shared language for an interactive terminal conversation with a Neuron AI
Agent.

## Language

**Neuron CLI**:
The reusable terminal module that runs a Conversation TUI for an Agent
supplied by a Host Application.
_Avoid_: Executable, command

**Agent**:
A ready-to-use Neuron AI agent whose capabilities and dependencies have
already been configured by the Host Application.
_Avoid_: Bot, model

**Host Application**:
The application that configures the Agent and starts the terminal
interaction.
_Avoid_: Neuron CLI, library

**Conversation TUI**:
The interactive terminal interface through which a person converses with an
Agent.
_Avoid_: Command, CLI application

**History**:
The sequence of messages owned by the Agent and represented by the TUI,
including messages that predate the TUI startup.
_Avoid_: Transcript, TUI log

**Slash command**:
Input beginning with `/` whose effect the TUI decides rather than the model.
What it does is code someone wrote, and that code is free to send the Agent
a prompt of its own.
_Avoid_: Message, prompt, action

**Controls**:
What a Slash command may do while it runs: say something in the conversation,
put a prompt to the Agent, offer a Picker, reach the Agent itself, leave the
terminal. A command allowed to run while the Agent is working is given fewer
of them.
_Avoid_: Context, facade, API

**Command kit**:
A group of Slash commands mounted in one go, carrying between them whatever
they need to work. A Conversation TUI mounts nothing on its own, so a kit is
the short way for a Host Application to say yes to several commands at once,
and it can be taken with some of them left out.
_Avoid_: Toolkit, bundle, plugin, pack

**Session**:
One conversation, identified by a key and held by a single History, that
outlives the TUI process and can be reopened. No Agent owns it: any Agent
can be handed it and carry it on. The default provider keeps its Sessions in
memory, so until a Host Application says where conversations live a Session
ends with the process.
_Avoid_: Chat, thread

**Session provider**:
The place the Sessions come from. It is the only thing that mints a key,
knows which Sessions exist, and can open one by its key.
_Avoid_: Store, repository, storage, archive

**Picker**:
The state the Conversation TUI is in while a person is choosing from a list
rather than writing to the Agent. Sessions are one of the things chosen this
way, not the only one.
_Avoid_: Menu, popup, dialog, Session picker

**Turn**:
One stretch of the conversation, from the moment a person's message is taken
for the Agent to the moment the Agent has finished answering it. A message
written while a turn is under way waits behind it.
_Avoid_: Round, exchange, request

**Working indicator**:
The animated line in the History that tells a person the Agent is still busy,
counting the seconds the turn has taken so far.
_Avoid_: Spinner, loader, progress bar
