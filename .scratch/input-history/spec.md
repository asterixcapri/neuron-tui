# Persistent Input history

Add Claude Code-style recall of earlier composer inputs after the shared
storage and TUI-owned Sessions refactor is complete.

Depends on: [`storage-and-sessions-refactor`](../storage-and-sessions-refactor/spec.md).

## Outcome

Up and Down recall inputs previously submitted through the Conversation TUI.
The recalled text appears in the composer, can be edited and can be submitted
again. Input history is TUI state shared across Session changes and persisted
by the configured `StorageInterface`.

## Recorded inputs

- Record every non-blank composer value submitted with Enter before it is
  interpreted as a Message or Command.
- Include Messages, recognized Commands, unknown Commands and Commands refused
  while a Turn is running.
- Collapse consecutive identical submissions into one entry.
- A queued Message becomes recallable as soon as it is submitted.
- Store entries independently of the Agent's History and Session namespace.
- Changing or clearing a Session does not clear Input history.

## Navigation

- While Command suggestions are open, Up and Down continue to move through the
  suggestions and do not navigate Input history.
- While a Picker is open, its existing key ownership remains unchanged.
- Otherwise, Up and Down first move through logical and visually wrapped rows
  of a multiline composer value.
- Up enters or advances toward older Input history only after the cursor cannot
  move farther upward. Down advances toward newer Input history only after the
  cursor cannot move farther downward.
- Recalling with Up places the cursor at the lower edge of the recalled value;
  recalling with Down places it at the upper edge, matching the direction from
  which the value was entered.
- Repeated movement at the oldest entry leaves it selected.
- Moving past the newest entry restores the draft that was present when
  navigation began, then exits history navigation.
- Editing a recalled value turns it into the current draft and exits history
  navigation without altering stored entries.
- Navigation with no stored entries is a no-op.

## Presentation

While a stored entry is selected, show `History <position>/<total>` adjacent to
the composer in the style of the existing status and frame. Positions are
one-based in chronological order: the newest of three entries is `History 3/3`.
Hide the indicator when the saved draft is restored or history navigation is
otherwise exited.

Programmatic recall of a Command must not open Command suggestions. Once the
person edits the recalled value or returns to the saved draft, ordinary
suggestion rules apply again.

## Persistence

Implement Input history as a module over `StorageInterface`. Load its entries
once when the Conversation Runtime starts, keep navigation in memory, and write
when a submission changes the stored entries. Arrow-key navigation performs no
storage reads or writes.

With `InMemoryStorage`, entries last for the TUI process. With `FileStorage`,
they survive closing and reopening the TUI with the same storage root. They are
shared by all Sessions using that storage.

## Boundaries

- Reverse search such as Claude Code's Ctrl+R is out of scope.
- Configurable keybindings and history-size limits are out of scope.
- Input history contains text only; attachments or paste-marker restoration are
  out of scope.
- Session persistence and Command terminology are established by the dependent
  refactor and are not changed here.

## Completion criteria

- Tests exercise draft preservation, both directions, both bounds, cursor-edge
  precedence, multiline and wrapped input, indicator positions and editing a
  recalled value.
- Tests exercise Commands, unknown/refused Commands, queued Messages,
  consecutive duplicates and blank submissions.
- Tests prove Input history survives Session changes and file-backed process
  recreation while the in-memory adapter writes nothing.
- Existing Command suggestions, Picker navigation, Page Up/Down scrolling,
  submission and queue behaviour remain covered and unchanged.
- Static analysis and the complete test suite pass.
