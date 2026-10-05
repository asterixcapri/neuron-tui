# Neuron TUI

Shared language for an interactive terminal conversation with a Neuron AI
Agent.

## Language

**Neuron TUI**:
The reusable terminal interface through which a person tests and converses with
an Agent configured by a Host Application.
_Avoid_: Neuron CLI, executable, command

**Agent**:
A ready-to-use Neuron AI agent whose provider, tools and dependencies have
already been configured by the Host Application.
_Avoid_: Bot, model

**Host Application**:
The application that configures the Agent and starts Neuron TUI.
_Avoid_: Neuron TUI, library

**Conversation TUI**:
The interactive terminal interface through which a person converses with an
Agent.
_Avoid_: Command, CLI application

**History**:
The sequence of messages owned by the Agent and represented by the TUI,
including messages that predate the TUI startup.
_Avoid_: Transcript, TUI log

**Notification**:
Feedback addressed to the person about the conversation or an error.
_Avoid_: Agent message, execution result

**Conversation**:
The ongoing exchange of messages between a person and an Agent, including
Agent responses and tool activity.
_Avoid_: TUI, event loop, Agent

**Turn**:
One stretch of the conversation, from the moment a person's message is submitted
to the Agent to the moment the Agent finishes responding. Text written during a
Turn remains a draft until the person submits it after that Turn ends.
_Avoid_: Round, exchange, request

**Working indicator**:
The animated status above the composer that tells a person the Agent is busy.
_Avoid_: Progress bar, elapsed-time counter
