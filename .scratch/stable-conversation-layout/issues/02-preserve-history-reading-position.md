# 02: Preserve the History reading position during updates

Status: ready-for-agent

**What to build:** Make the bounded conversation viewport distinguish between
following the latest History and reading an earlier position. While following
the latest content, new Agent text, tool activity and transient History entries
remain visible automatically. Once the person scrolls upward, changes in
History height must preserve the content being read instead of pulling the
viewport toward the newest output. Returning to the bottom restores automatic
following.

This slice is complete when:

- New content remains visible automatically while the viewport is at the
  bottom.
- Scrolling upward establishes a stable reading position.
- Agent text arriving while scrolled upward does not replace the content being
  read.
- Adding, updating or removing the Working indicator while scrolled upward does
  not move the content being read.
- Tool calls and tool results changing the History while scrolled upward do not
  pull the viewport to the bottom.
- Scrolling back to the latest content re-enables automatic following for later
  updates.
- Behavior remains correct when the terminal is resized while following or
  while reading earlier History.
- High-level virtual-terminal tests drive real scrolling input and assert the
  reconstructed visible screen, without exposing viewport internals.

**Blocked by:** 01 — Anchor the Lower controls with a bounded viewport.
