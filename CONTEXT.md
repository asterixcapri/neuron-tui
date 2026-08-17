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
Input beginning with `/` that is interpreted locally by the TUI instead of
being sent to the Agent.
_Avoid_: Message, prompt

**Session**:
One conversation with the Agent, identified by a key and held by a single
History, that outlives the TUI process and can be reopened.
_Avoid_: Chat, thread

**Session store**:
The place the Sessions of an Agent live. It is the only thing that knows
which Sessions exist and how to reach one by its key.
_Avoid_: Repository, storage, archive

**Session picker**:
The state the Conversation TUI is in while a person is choosing a Session
rather than writing to the Agent.
_Avoid_: Menu, popup, dialog

**Working indicator**:
The animated line in the History that tells a person the Agent is still busy,
counting the seconds the turn has taken so far.
_Avoid_: Spinner, loader, progress bar
