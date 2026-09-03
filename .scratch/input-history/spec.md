# Persistent Input history

Add simple recall of earlier composer inputs after the shared storage and
TUI-owned Sessions refactor is complete.

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
- Otherwise, Up enters Input history only while the composer is empty and
  recalls the newest stored entry.
- A non-empty composer value written by the person keeps the editor's ordinary
  Up and Down behaviour and cannot enter Input history.
- Once Input history navigation has begun, Up advances toward older entries and
  Down advances toward newer entries until the recalled value is edited or
  submitted.
- Every recalled value places the cursor at the end of its text.
- Repeated movement at the oldest entry leaves it selected.
- Moving past the newest entry restores the empty composer and exits history
  navigation.
- Editing a recalled value turns it into the current draft and exits history
  navigation without altering stored entries.
- Navigation with no stored entries is a no-op.

Programmatic recall of a Command must not open Command suggestions. Once the
person edits the recalled value or returns to the empty composer, ordinary
suggestion rules apply again.

## Persistence

Implement Input history as a module over `StorageInterface`. Load its entries
once when the Conversation Runtime starts, keep navigation in memory, and write
when a submission changes the stored entries. Arrow-key navigation performs no
storage reads or writes.

With `InMemoryStorage`, entries last as long as that storage instance. With
`FileStorage`, they survive closing and reopening the TUI with the same storage
root. They are shared by all Sessions using that storage.

## Boundaries

- Reverse search such as Claude Code's Ctrl+R is out of scope.
- Configurable keybindings and history-size limits are out of scope.
- Entering Input history from a non-empty draft, preserving that draft and
  navigating logical or visually wrapped composer rows before entering Input
  history are out of scope.
- Input history has no position or count indicator in the composer.
- Input history contains text only; attachments or paste-marker restoration are
  out of scope.
- Session persistence and Command terminology are established by the dependent
  refactor and are not changed here.

## Completion criteria

- Tests exercise entry from an empty composer, refusal to enter from a non-empty
  composer, both directions, both bounds, cursor placement and editing a
  recalled value.
- Tests prove multiline and visually wrapped drafts retain the editor's existing
  Up and Down behaviour rather than entering Input history.
- Tests exercise Commands, unknown/refused Commands, queued Messages,
  consecutive duplicates and blank submissions.
- Tests prove Input history survives Session changes and file-backed process
  recreation, while the default in-memory composition writes nothing to disk.
- Existing Command suggestions, Picker navigation, Page Up/Down scrolling,
  submission and queue behaviour remain covered and unchanged.
- Static analysis and the complete test suite pass.
