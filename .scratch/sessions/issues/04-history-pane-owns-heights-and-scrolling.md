# 04 — The History pane owns heights and scrolling

**What to build:** One module owns everything about the painted History:
the widgets, how many there are, the gaps between them, how tall each one is,
and the scroll offset. Entries that change after being shown — streamed text,
a tool call waiting for its result — are updated through an opaque handle the
pane hands back, and the pane works out what changed by itself.

Today three callers each keep their own height counter and are obliged to
report every change; forgetting is what makes the reading position jump. That
obligation leaves the interface entirely.

The pane also gains the ability to clear itself, which the Session commands
will need.

**Blocked by:** 01 — Module layout without the Internal segment

**Status:** done

- [x] One module owns the painted entries, their heights, the gaps and the
      scroll offset
- [x] Adding an entry returns a handle; updating through the handle needs no
      height arithmetic from the caller
- [x] No caller reports a height change any more
- [x] The pane can be cleared, leaving the TUI ready to paint a different
      History
- [x] The reading position is preserved while scrolled up and content grows
      below, and following resumes at the bottom
- [x] Tool activity no longer receives callbacks to report its own size
- [x] The existing test suite passes without a single test being edited

## Comments

Done in `NeuronCli\Tui\HistoryPane` and `NeuronCli\Tui\HistoryEntry`.

- The pane owns the entry widgets, their measured heights, the one-line gaps
  and the reading position. Every mutation — adding an entry, writing through
  a handle, removing one, clearing — recomputes the painted height and moves
  the reading position by the difference, so no caller can forget to report a
  change.
- `addMessage()` and `addNote()` return a `HistoryEntry` handle. `setText()`
  and `appendText()` are the whole of what a caller does; the two accessors
  the handle still exposes (`widget()`, `height()`) are for the pane alone.
- `ToolActivity` now takes the pane and keeps handles; its two height
  callbacks and its own height bookkeeping are gone.
- `ConversationView` keeps no height counter, no scroll offset and no widget
  measuring at all.
- `HistoryPane::clear()` empties the pane and returns the reading position to
  the bottom. The `ConversationView` wrapper for it was dropped as dead code:
  the Session commands ticket that calls it should add it, and reset the
  active agent-message and working-indicator handles at the same time.
- New direct tests in `tests/Tui/HistoryPaneTest.php` (6). No existing test
  was edited.

Verification: `composer stan` clean, `composer test` 27 tests / 136
assertions green.

Known flake, pre-existing and unrelated: `NeuronCliTest::
testHostApplicationCanCustomizeConversationBranding` fails intermittently
(about 1 run in 5, sometimes producing empty terminal output). Reproduced at
the base commit 9ff25b9 with this branch's changes stashed, at the same rate.

Left for later, both pre-existing: entry heights are measured once and are not
re-measured on a terminal resize, and the scroll offset is not clamped to the
content height.
