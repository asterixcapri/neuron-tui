# 01: Anchor the Lower controls with a bounded viewport

Status: ready-for-agent

**What to build:** Keep the Composer and Status line anchored to the bottom of
the terminal by placing the header, History and queued messages inside a
vertically expanding conversation viewport. The viewport must emit no more rows
than the layout assigns to it, including when its own non-History content is
taller than the available region.

The viewport follows the latest content by default and retains the existing
ability to scroll upward and downward. Scroll ownership must be local to the
conversation viewport rather than implemented by changing terminal-wide
rendering behavior. Symfony TUI and installed dependencies must remain
unmodified, and the Host Application API must not change.

This slice is complete when:

- A long History cannot move the Lower controls away from the bottom rows.
- Adding and removing the Working indicator leaves the Lower controls on the
  same rows.
- Opening and closing Command suggestions may change the visible amount of
  History but does not move the Lower controls.
- Queued messages cannot push the Lower controls off their anchor, including on
  a small terminal.
- Resizing the terminal moves the Lower controls only to the newly calculated
  bottom rows.
- A short History remains aligned beneath the header with unused space above
  the Lower controls.
- Existing upward and downward History navigation continues to work.
- A high-level virtual-terminal regression reconstructs the physical screen and
  asserts visible rows rather than widget structure.

**Blocked by:** None (can start immediately).
