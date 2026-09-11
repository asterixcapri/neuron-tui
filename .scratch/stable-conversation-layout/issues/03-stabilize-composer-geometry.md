# 03: Stabilize the Composer geometry

Status: ready-for-agent

**What to build:** Keep the bottom edge of the Lower controls stable as the
Composer changes height. The Composer continues to show between one and five
editable lines and grows upward. Its prompt aligns with the first editable line
instead of being vertically centered. The Status line occupies exactly one row
and truncates when the terminal is too narrow instead of wrapping.

This slice is complete when:

- A one-line Composer retains its current minimum height.
- Adding editable lines grows the Composer upward while its bottom edge remains
  anchored.
- The prompt is aligned with the first editable line for every visible Composer
  height.
- Five editable lines remain the maximum visible Composer height.
- Content beyond five lines remains editable without increasing the visible
  height.
- The Status line stays one row high on narrow terminals and truncates content
  that does not fit.
- Opening and closing Command suggestions does not change these Composer and
  Status line invariants.
- High-level virtual-terminal tests cover one through five visible lines, input
  beyond the maximum and a narrow terminal by asserting reconstructed screen
  rows rather than widget configuration.

**Blocked by:** None (can start immediately).
