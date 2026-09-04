# The Conversation TUI mounts nothing on its own

_ADR 0003 supersedes only this decision's duplicate-name rule. The TUI still
mounts nothing on its own._

_The Refine Interaction composition revision supersedes the concurrent marker
and restricted controls policy below. HelpCommand and LeaveCommand now belong
to Neuron Interaction and implement the ordinary CommandInterface with
CommandControlsInterface. The TUI admits only those implementations during a
Turn, including aliases; unrelated Commands are refused regardless of name.
There is no concurrent marker, wrapper or restricted controls contract. Leave
stops the terminal, pending Picker and queued-input processing without cancelling
or waiting for in-flight Agent work. No Commands are mounted automatically._

The package used to answer three Commands — `/clear`, `/sessions` and
`/exit` — carried out by the Conversation TUI itself, and the source said as
much: a fixed set of commands does not justify a registry. That reasoning held
only while the set was fixed. Once a Host Application can mount a Command
of its own, a registry exists anyway, and keeping three commands outside it
would mean two ways of answering a name, two places to look one up, and three
names a Host Application may not use.

So the decision is inverted. **The Conversation TUI mounts nothing on its own.**
A terminal built without naming a command is a terminal where every name after
a slash is unknown, and where `Ctrl+C` is the way out. `ClearCommand`,
`ResumeCommand`, `LeaveCommand` and `HelpCommand` are shipped with the library
as classes a Host Application mounts exactly as it mounts its own, each taking
the name it answers to at construction so `/quit` costs nothing but an
argument.

Two things follow from the commands being ordinary.

**The Session provider leaves the constructor of the Conversation TUI.** The
place conversations live is what `ClearCommand` and `ResumeCommand` need, so
they are the ones that receive it, and the TUI stops knowing about it
altogether. Opening a conversation becomes installing a History on the Agent —
something a command already does through `Controls::agent()` and the Neuron AI
API — rather than a verb of its own.

**The conversation is reconciled after every command.** Since a command may
replace the History on the Agent without saying so, the Conversation TUI reads
the History back from the Agent once `run()` has returned, and repaints when it
is no longer the one the command was handed. A command that left the
conversation alone leaves the screen alone too, so what it said, warned or
asked stays where it was written.

Nothing had been released, so the interface break costs nobody anything.

## Consequences

- Building the Conversation TUI without commands gives a terminal that chats
  and is left with `Ctrl+C`. `/clear`, `/resume`, `/exit` and `/help` are unknown
  names until a Host Application mounts the commands that answer them.
- `Tui` no longer takes a Session provider. A Host Application that wants
  Sessions passes its provider to `ClearCommand` and to `ResumeCommand`, and
  passing the same one to both is what makes the two agree on which
  conversations exist.
- No name is reserved any more: a Host Application may mount a command of its
  own under `/clear`. ADR 0003 defines what happens when more than one mounted
  command answers to the same name.
- The screen is repainted from the Agent's History whenever a command changed
  it, so a command need not — and cannot usefully — tell the TUI what it did.
- Which commands a turn under way does not hold back is carried by the type of
  a command: one implementing `ConcurrentCommandInterface` rather than `CommandInterface` is
  carried out at any time, and is handed the `ConcurrentControls` in exchange.
  The Conversation TUI names no class of its own to decide it, so a Host
  Application's own command to close the terminal runs mid-turn on the same
  terms as the shipped `LeaveCommand`.
- The screen is reconciled by comparing the History the command was handed with
  the one the Agent holds afterwards, so a command that changed a History in
  place rather than replacing it leaves the screen as it was.
