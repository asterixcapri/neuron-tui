# Spec: A resume-style Picker for `choose()`

Status: ready-for-agent

## Problem Statement

The Picker does not look or feel sufficiently different from the Command
suggestions shown while a Slash command name is being written. The two already
have different meanings: Command suggestions remain attached to the composer,
whereas a Picker temporarily puts the Conversation TUI into a choosing state.
The current presentation does not make that change of state clear enough.

The current Picker also hides the text used to narrow its options inside its
instruction line, gives every option only one display line, and presents a
generic list without the stronger visual hierarchy of a deliberate choice.
That makes it unsuitable for richer choices such as Sessions, where a concise
secondary detail helps distinguish similarly named options.

## Solution

Present every choice made through `Controls::choose()` as a focused lower
panel modelled on the visual language of Claude Code's `/resume` panel. While
the Picker is open, it replaces the composer and status, leaves the History
visible, and clearly separates the act of choosing from writing to the Agent.

The panel has a separator, a title with the selected position and result
count, an optional explanatory description, a visible search field when the
choice is large enough, a scrollable list of complete option blocks, and
persistent keyboard instructions. Each option has a key, a label and an
optional detail displayed beneath the label.

There is one form of `choose()` and one form of choice option. Slash commands
describe what may be chosen; the Conversation TUI owns how the choice is
rendered and operated.

## User Stories

1. As a person using the Conversation TUI, I want a Picker to look distinct
   from Command suggestions, so that I understand I am choosing rather than
   completing a Slash command name.
2. As a person using the Conversation TUI, I want the Picker to replace the
   composer while it is open, so that I do not mistake typing a filter for
   writing to the Agent.
3. As a person using the Conversation TUI, I want the History to remain
   visible while choosing, so that I retain the context in which the choice
   was offered.
4. As a person using the Conversation TUI, I want a separator above the
   Picker, so that the temporary panel is visually distinct from the History.
5. As a person using the Conversation TUI, I want to see what I am being asked
   to choose, so that the options have an explicit context.
6. As a person using the Conversation TUI, I want a choice to be able to
   explain itself beneath its title, so that unfamiliar decisions need not be
   encoded into option labels.
7. As a person using the Conversation TUI, I want an absent explanation to
   consume no blank line, so that simple choices remain compact.
8. As a person using the Conversation TUI, I want long explanations to wrap,
   so that they remain readable in a terminal.
9. As a person using the Conversation TUI, I want an explanation to stop
   after three visual lines, so that it cannot dominate the panel.
10. As a person using the Conversation TUI, I want to see my selected
    position and the number of available results, so that I know where I am
    within a choice.
11. As a person using the Conversation TUI, I want a visible search field for
    a choice with at least six options, so that I know where my typing goes.
12. As a person using the Conversation TUI, I want a choice with at most five
    options to omit the search field, so that a small choice remains simple.
13. As a person using the Conversation TUI, I want search to update as I
    type, so that I can narrow a long list without submitting a separate form.
14. As a person using the Conversation TUI, I want search to ignore letter
    case, so that capitalisation does not prevent a match.
15. As a person using the Conversation TUI, I want search to match contiguous
    text anywhere in the label or detail, so that either visible part can help
    me find an option.
16. As a person using the Conversation TUI, I want matching options to retain
    the order in which the Slash command supplied them, so that the list does
    not jump unpredictably while I type.
17. As a person using the Conversation TUI, I want the counter to reflect the
    filtered results, so that it describes the list currently in front of me.
18. As a person using the Conversation TUI, I want a clear no-match message,
    so that an empty list is distinguishable from a rendering failure.
19. As a person using the Conversation TUI, I want Enter to do nothing when
    there are no matches, so that a hidden or stale option cannot be chosen.
20. As a person using the Conversation TUI, I want each option's label and
    detail to start in the same column, so that the list is easy to scan.
21. As a person using the Conversation TUI, I want the selection arrow to sit
    to the left of the option text, so that alignment does not change when an
    option is selected.
22. As a person using the Conversation TUI, I want the selected label to use
    the accent colour, so that the active option is immediately apparent.
23. As a person using the Conversation TUI, I want the detail to retain a
    lighter secondary colour, so that supporting information does not compete
    with the label.
24. As a person using the Conversation TUI, I want a blank line between
    options, so that each label and detail read as one visual block.
25. As a person using the Conversation TUI, I want long labels and details to
    continue on an aligned second line, so that useful text is not truncated
    prematurely.
26. As a person using the Conversation TUI, I want labels and details longer
    than two visual lines each to end in an ellipsis, so that one option cannot
    occupy the entire panel.
27. As a person using the Conversation TUI, I want the Picker to show no more
    than four complete options at once, so that the History retains useful
    space.
28. As a person using the Conversation TUI, I want fewer options shown when a
    short terminal cannot fit four complete blocks, so that an option is never
    split between visible and hidden content.
29. As a person using the Conversation TUI, I want to scroll through every
    supplied option, so that the visible limit does not make any choice
    unreachable.
30. As a person using the Conversation TUI, I want Up and Down to move the
    selection even while the search field is active, so that choosing does not
    require switching focus.
31. As a person using the Conversation TUI, I want navigation to wrap between
    the first and last results, so that it retains the current Picker
    behaviour.
32. As a person using the Conversation TUI, I want Enter to choose the active
    option, so that selection remains a single keystroke.
33. As a person using the Conversation TUI, I want Escape to cancel the
    Picker, so that I can decline a choice without a special option.
34. As a person using the Conversation TUI, I want Backspace to edit the
    search query, so that I can recover from a typing error.
35. As a person using the Conversation TUI, I want the keyboard instructions
    to remain visible beneath the options, so that the available actions are
    discoverable.
36. As a person using the Conversation TUI, I want every newly opened Picker
    to start with an empty search and its first option selected, so that a
    previous choice does not affect a later one.
37. As a Slash command author, I want every option represented by one
    `ChoiceOption`, so that simple and detailed choices do not use competing
    interfaces.
38. As a Slash command author, I want the key kept separate from visible text,
    so that changing a label or detail does not change the value returned by
    the choice.
39. As a Slash command author, I want the option detail to be optional, so
    that uncomplicated choices do not need filler text.
40. As a Slash command author, I want `choose()` to return the selected key,
    so that the command can map the choice back to its own data.
41. As a Slash command author, I want cancellation to return `null`, so that
    it remains a normal result rather than an exception.
42. As a Slash command author, I want invalid choices rejected before the
    screen changes, so that programming errors cannot strand the terminal in
    a broken Picker.
43. As a Host Application author, I want presentation policy to remain inside
    the Conversation TUI, so that every mounted command gets a consistent
    Picker without configuring search, counters or instructions.
44. As a Host Application author, I want `LimitedControls` to continue
    excluding `choose()`, so that a Picker cannot be opened during a Turn.

## Implementation Decisions

- `Controls` continues to expose one semantic operation for a single choice.
  There is no simple/advanced pair and no generic operation for presenting a
  composition of TUI elements.
- `choose()` receives a title, a non-empty ordered list of `ChoiceOption`
  values, and an optional panel description. It returns the selected option's
  key or `null` when the person cancels or the Conversation TUI closes.
- `ChoiceOption` is the sole representation of an option. It carries a key, a
  label and an optional detail. Plain strings are not an alternative option
  representation.
- Option keys are not displayed and are returned unchanged. Keys must be
  unique. The list and labels must be non-empty. A detail may be absent, but
  when present it must not be empty. Invalid input raises
  `InvalidArgumentException` before the visible TUI changes.
- Only one Picker may be open. A concurrent second choice retains the current
  `LogicException` behaviour.
- Opening the Picker replaces the composer and ordinary status line with a
  lower panel. The History remains visible. Closing the Picker restores the
  composer, ordinary status and its focus.
- The panel follows the visual hierarchy of Claude Code's `/resume`: a top
  separator, title and counter, optional description, conditional search
  field, option list, and instructions at the bottom.
- The title shows the selected result position and the current result count.
  Without a query, the count is the supplied option count. While filtering,
  it is the number of matches. With no matches it reads `0 of 0`.
- The optional panel description appears directly below the title. Its
  absence leaves no placeholder row. It wraps naturally up to three visual
  lines and is abbreviated with an ellipsis beyond that limit.
- Search appears when the original choice contains at least six options. Once
  shown, it remains visible even when filtering reduces the result count below
  six. Choices containing at most five options have no search field and do
  not accept search text.
- Search uses case-insensitive contiguous-substring matching against the full,
  unabridged label and detail. An absent detail contributes no searchable
  text. Matches retain their supplied order; there is no fuzzy score or
  relevance reordering.
- Changing the query resets the active selection to the first matching
  option. Every opening starts with an empty query and the first supplied
  option selected.
- With no matches, the options are replaced by `No options match "<query>"`,
  the counter reads `0 of 0`, and Enter has no effect. Escape and Backspace
  remain available.
- Each option is a visual block. Its label and optional detail begin in the
  same column, with the selection arrow occupying a separate column to their
  left. Continuation lines retain the text alignment.
- The selected label uses the Conversation TUI accent colour. Detail text
  retains a lighter secondary colour whether or not its option is selected.
- A blank row separates adjacent option blocks. There is no required blank
  row after the final visible option.
- Labels and details wrap independently for up to two visual lines each. Text
  beyond the second line is abbreviated with an ellipsis. Supplied line breaks
  are normalised as whitespace before terminal-width wrapping.
- The viewport contains at most four complete option blocks and may contain
  fewer when the available terminal height cannot fit four. It never displays
  only part of an option block. All remaining options are reachable by
  scrolling.
- Up and Down move the option selection even when search is active and wrap at
  the ends. Enter chooses the active option. Escape cancels. Backspace edits
  the query. The footer always communicates movement, choice and cancellation.
- Picker state owns the query, filtered options, active option and viewport.
  That state is reset rather than retained after a choice closes.
- The Slash command waits for `choose()` while the event loop continues to
  receive input and render. Selection, cancellation and terminal shutdown
  complete the wait exactly once.
- `LimitedControls` remains unchanged and does not expose `choose()`, so a
  command that runs during a Turn cannot open a Picker.
- The Conversation TUI continues to own Symfony TUI widgets, focus, input
  routing, rendering, sanitisation, viewport calculations and asynchronous
  waiting. Slash commands never receive or compose TUI widgets.
- The existing third-party select-list widget cannot render the required
  two-line option blocks with independent label/detail styling or a viewport
  measured in visual rows. The implementation therefore needs an internal
  Picker-list module rather than forcing multiline data through that widget.
- Command suggestions are unchanged. They remain attached to the composer and
  are not part of the Picker state or redesign.
- No ADR or glossary addition is required. This is a normal evolution of the
  existing Picker; `ChoiceOption` is an interface type rather than a new domain
  concept.

## Testing Decisions

- The primary and preferred test seam is the existing complete interaction:
  a Slash command calls `Controls::choose()`, `VirtualTerminal` supplies input,
  and the rendered Conversation TUI and returned key are observed. Tests do
  not reach into Picker widgets or internal state.
- Existing Conversation TUI integration tests for command-owned choices,
  filtering, arrow navigation, Escape, terminal shutdown and reopening a
  Picker are the prior art. They should be adapted to the new interface and
  expanded rather than duplicated at a lower seam.
- Rendering tests cover the panel separator, title, counter, optional
  description, conditional search field, option alignment, detail styling,
  spacing, footer, and absence of the composer and ordinary status while the
  Picker is open.
- Threshold tests cover five options without search and six options with
  search.
- Search tests cover label matches, detail matches, case insensitivity,
  contiguous substring behaviour, stable ordering, selection reset and
  matching against text before visual wrapping or abbreviation.
- Empty-result tests cover the message, `0 of 0`, inert Enter, Backspace
  recovery and Escape cancellation.
- Option rendering tests cover missing details, wrapped labels, wrapped
  details, the two-line limit, ellipses, aligned continuation lines, blank
  rows and selected versus secondary colours.
- Viewport tests cover no more than four complete blocks, a short terminal
  showing fewer blocks, scrolling in both directions, wraparound navigation,
  and never splitting a block.
- Lifecycle tests cover a fresh query and selection on every opening, restored
  composer/status/focus, cancellation on terminal shutdown, and completion of
  the waiting Slash command exactly once.
- Contract tests cover the exact returned key and validation of an empty
  option list, duplicate keys, empty labels and empty present details before
  any visible state changes.
- `LimitedControls` remains covered by its public surface: no test introduces
  a runtime rejection for `choose()` because the operation remains absent from
  that interface.
- If viewport and wrapping logic becomes substantial, it may be isolated in a
  pure internal module and tested through input/output examples. This is an
  internal seam only; the complete Slash command plus `VirtualTerminal`
  interaction remains the feature's authoritative test surface.
- Tests assert observable text, terminal styling, navigation and returned
  results. They avoid class names, widget trees, listener counts and other
  implementation details that can change without affecting behaviour.

## Out of Scope

- Changing the appearance or behaviour of Command suggestions.
- Adding `chooseSimple()`, `chooseAdvanced()` or a generic `present()`
  operation.
- Giving Slash commands access to Symfony TUI widgets or a catalogue of
  composable visual elements.
- Reproducing Claude Code's specialised `/model` controls, Session preview,
  rename actions, branch filters or other domain-specific actions.
- Adding confirmation, free-text, multi-selection or progress interactions to
  `Controls`.
- Fuzzy matching, relevance ranking or reordering options during search.
- Allowing a Picker to open while a Turn is in progress.
- Mouse interaction.
- Changing the meaning of Session, the Session provider or any shipped Slash
  command beyond adapting its use of `choose()` to `ChoiceOption`.

## Further Notes

- The visual reference was Claude Code 2.1.239, observed directly in `/resume`
  and `/model`. `/resume` is the reference for the generic Picker; `/model`
  only demonstrated the optional explanatory text below a panel title.
- The design deliberately keeps the command-facing module deep: a small
  semantic interface provides search, rendering, navigation, lifecycle and
  responsive behaviour while preserving locality inside the Conversation TUI.
- The interface change intentionally replaces key-to-label string maps with a
  single ordered `ChoiceOption` representation so simple and detailed choices
  cannot drift into separate dialects.
