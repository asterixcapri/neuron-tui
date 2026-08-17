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

**Status:** ready-for-agent

- [ ] One module owns the painted entries, their heights, the gaps and the
      scroll offset
- [ ] Adding an entry returns a handle; updating through the handle needs no
      height arithmetic from the caller
- [ ] No caller reports a height change any more
- [ ] The pane can be cleared, leaving the TUI ready to paint a different
      History
- [ ] The reading position is preserved while scrolled up and content grows
      below, and following resumes at the bottom
- [ ] Tool activity no longer receives callbacks to report its own size
- [ ] The existing test suite passes without a single test being edited
