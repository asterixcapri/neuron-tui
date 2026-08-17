# 07 — The working indicator as one module

**What to build:** The animation that tells a person the Agent is still busy
becomes one module: the frames, the elapsed counter, the rate at which it
redraws, and the line on screen. Today it is split down the middle — the public
module keeps the frames and the stopwatch, the view keeps the widget — and
neither owns it, which is why the caller has to know to stop it before a tool
result and start it again after.

Time is handed to the module rather than read from the clock inside it, so both
the elapsed counter and the redraw throttle can be tested without sleeping.

What a terminal user sees does not change.

**Blocked by:** 04 — The History pane owns heights and scrolling

**Status:** ready-for-agent

- [ ] One module owns the frames, the elapsed seconds, the throttle and the
      widget
- [ ] The current time is passed in; the module neither reads the clock nor
      takes an injected clock adapter
- [ ] Pausing and resuming around a tool result replace the ordering constraint
      the caller has to remember today
- [ ] The public module no longer holds animation state or animation methods
- [ ] The view's interface no longer exposes the three working-indicator
      methods
- [ ] Direct tests cover the elapsed counter and the throttle without sleeping
- [ ] The existing test suite passes without a single test being edited
