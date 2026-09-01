# Enter takes the selected Command suggestion

Status: ready-for-agent

## Problem Statement

While a person is writing a Slash command name, the Conversation TUI shows
matching Command suggestions and marks one as selected. The arrow keys can move
that selection, but pressing Enter currently sends the incomplete draft rather
than taking the selected suggestion. The visible selection therefore promises
an action that Enter does not perform and can produce an unknown Slash command
even though the intended mounted command is highlighted on screen.

## Solution

When Command suggestions are open, Enter takes the selected suggestion,
completes its full Slash command name, and executes it immediately. This applies
both to the first suggestion selected automatically when the list opens and to
a different suggestion selected with the arrow keys.

Tab keeps its existing, distinct role: it completes the selected name and
leaves the composer open so the person can write arguments before submitting.
When no Command suggestion can be selected, Enter retains its existing meaning
and sends exactly what is written. The Picker and its Enter behaviour do not
change.

## User Stories

1. As a person using the Conversation TUI, I want Enter to execute the Command
   suggestion marked by the selection arrow, so that the interface does what
   its visual state promises.
2. As a person who has typed only part of a Slash command name, I want Enter to
   take the selected matching command, so that I do not need to finish typing
   its name manually.
3. As a person opening Command suggestions, I want Enter to take the first
   suggestion selected automatically, so that selection behaves consistently
   before and after arrow navigation.
4. As a person navigating Command suggestions with the arrow keys, I want Enter
   to execute the suggestion I moved to, so that it does not execute the first
   match or submit my unfinished prefix.
5. As a person choosing between similarly named Slash commands, I want the
   selected full command name to replace my prefix before submission, so that
   the intended mounted command receives the input.
6. As a person invoking a Slash command without arguments, I want one press of
   Enter to both take and execute the selected suggestion, so that confirmation
   does not require a second Enter.
7. As a person invoking a Slash command that needs arguments, I want Tab to
   continue completing the selected name without executing it, so that I can
   write those arguments first.
8. As a person using the composer when no mounted command matches my draft, I
   want Enter to keep sending exactly what I wrote, so that the suggestion
   feature does not silently substitute an unavailable command.
9. As a person who dismissed the Command suggestions, I want Enter to retain
   the composer's normal submission behaviour, so that an off-screen selection
   cannot affect my input.
10. As a person writing an ordinary message rather than a Slash command name, I
    want Enter to keep sending that message, so that command completion does not
    alter normal conversation input.
11. As a person using a Picker opened by a Slash command, I want Enter to keep
    choosing the highlighted Picker option, so that this change does not alter
    an already-correct interaction.
12. As a person resuming a Session, I want the Session Picker to remain
    unchanged, so that selecting a Session continues to use the existing Picker
    contract.
13. As a Host Application author, I want Enter to execute the selected command
    from the mounted command list, so that custom Slash commands receive the
    same suggestion behaviour as the commands shipped by Neuron TUI.
14. As a Host Application author with commands mounted in a deliberate order, I
    want the automatically selected first match to remain determined by the
    existing ordering rules, so that this feature changes confirmation rather
    than ranking.
15. As a person using the Conversation TUI while an Agent is working, I want
    Enter to take a selected suggestion only from the commands that are
    currently offered, so that it does not bypass the existing mid-turn command
    restrictions.
16. As a person learning the controls from the status line and documentation, I
    want them to describe Enter as taking the selected Command suggestion, so
    that the written guidance matches the interaction.

## Implementation Decisions

- The change belongs to Command suggestions, not to the Picker. The two may
  look similar, but the Picker suspends a command while a choice is made,
  whereas Command suggestions remain part of writing in the composer.
- Enter is handled as a Command-suggestion key only while a selectable
  suggestion list is open.
- When Enter is handled by Command suggestions, the selected suggestion's full
  Slash command name replaces the incomplete draft and is submitted in the
  same input action.
- The first suggestion is already selected when the list opens and is eligible
  for immediate execution; arrow navigation is not required.
- After arrow navigation, the selected suggestion at the moment Enter is
  pressed is the command that is executed.
- Enter does not append an argument-separating space before immediate
  execution. The selected command is submitted without arguments unless the
  command name already came from an exact draft governed by existing parsing
  rules.
- Tab retains the existing completion behaviour: it writes the selected full
  command name followed by a space and does not submit it.
- If the suggestions area says that no commands match, if suggestions were
  dismissed, or if no suggestion list is open, Enter continues through the
  composer's existing submission path unchanged.
- Existing suggestion filtering, ranking, mounted-command ordering, arrow-key
  navigation, Escape dismissal, and availability while the Agent is working
  remain unchanged.
- Existing Slash command parsing and dispatch remain the authority that
  executes the completed name. Command suggestions do not invoke command
  objects directly.
- The status text and user documentation are updated to distinguish “Enter
  runs” from “Tab completes.”
- The domain glossary continues to distinguish Command suggestions from the
  Picker and records that a selected suggestion can either be completed for
  further writing or taken immediately.
- No ADR is required: the behaviour is local, straightforward to reverse, and
  does not introduce a new architectural trade-off.

## Testing Decisions

- Use one existing high-level seam: exercise the complete Conversation TUI
  through its virtual terminal and assert externally observable command
  behaviour.
- A good test enters the same keystrokes a person would use and observes which
  mounted Slash command ran, which arguments it received, and whether an
  unknown-command error appeared. It does not assert private selection indexes,
  internal callbacks, or widget implementation details.
- Cover Enter with the automatically selected first suggestion after only a
  command prefix has been written.
- Cover arrow navigation followed by Enter and prove that the newly selected
  command runs with no arguments.
- Preserve coverage proving that Tab completes the selected name, leaves the
  command unexecuted, and permits arguments to be written before submission.
- Preserve or add coverage proving that Enter sends the unchanged draft when
  no command matches or when no suggestion list is open.
- Rely on the existing Picker integration coverage to guard `/resume`, generic
  choices, filtering, arrow navigation, cancellation, and reopening. No new
  lower-level Picker seam is needed because the Picker is outside the change.
- Follow the existing terminal-driven tests for Command suggestions as prior
  art: they already cover filtering, ordering, arrow movement, Tab completion,
  Escape dismissal, status text, and command availability during a Turn.

## Out of Scope

- Changing the Picker or its keyboard contract.
- Changing how `/resume` lists or resumes Sessions.
- Changing suggestion filtering, matching, ranking, ordering, or rendering.
- Adding mouse interaction or new keybindings.
- Changing how Slash commands parse or receive arguments.
- Executing a suggestion after Tab without a later Enter.
- Making an unavailable command executable while the Agent is working.
- Introducing a new public API or a new testing seam.

## Further Notes

The current implementation deliberately leaves Enter to the composer even
while Command suggestions are visible. Existing documentation describes that
old behaviour, so both implementation comments and user-facing guidance must be
reviewed for stale statements.

The Picker already implements the interaction that originally motivated the
report: Enter chooses its highlighted option. This spec intentionally brings
Command suggestions into alignment only at the level of what the selection
arrow promises; it does not merge the two domain concepts or their internal
control flow.
