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

**Status:** done

- [x] One module owns the frames, the elapsed seconds, the throttle and the
      widget
- [x] The current time is passed in; the module neither reads the clock nor
      takes an injected clock adapter
- [x] Pausing and resuming around a tool result replace the ordering constraint
      the caller has to remember today
- [x] The public module no longer holds animation state or animation methods
- [x] The view's interface no longer exposes the three working-indicator
      methods
- [x] Direct tests cover the elapsed counter and the throttle without sleeping
- [x] The existing test suite passes without a single test being edited

## Comments

### The module

`Tui\WorkingIndicator` owns the frames, the elapsed counter, the redraw
throttle and the line in the History pane. It holds a `HistoryPane`, adds its
own note, keeps the handle, and removes it again — nobody else knows the line
exists.

Every operation that needs the time takes it as a parameter: `start(float
$now)`, `advance(float $now)`, `whilePaused(float $now, Closure $paint)`.
There is no clock field and no clock adapter. `NeuronCli` reads
`microtime(true)` at the three call sites, which is the only place a real
clock appears.

### Pausing instead of a remembered ordering

The indicator is always the last entry in the History, so anything painted
while it shows would land above it. Today the caller has to remember
`stopWorking(); …paint…; resumeWorking();` around a tool result.

`whilePaused($now, $paint)` takes what has to be painted instead: the module
hides its line, runs the closure, and shows the line again underneath, with
the elapsed counter still counting from the original start. The ordering can
no longer be got wrong, because the caller no longer states it.

### What the view kept

`startWorking()`, `updateWorkingFrame()` and `stopWorking()` are gone from
`ConversationView`, along with the `$loading` handle and the `workingText()`
formatter. The view constructs the indicator over its own pane and hands it
out through `workingIndicator()`.

That accessor was reviewed as a possible Middle Man. It stays because the
alternative is worse: the indicator needs the `HistoryPane`, and exposing the
pane would widen the view's interface far more than one getter for one
collaborator does.

`ConversationView::working()` remains, and is not an indicator method — it
sets the composer's status hint and is the counterpart of `ready()`. Both
pairings are named in `NeuronCli`: `startWorking()` and `stopWorking()` are
one private method each, so no call site holds two calls that must stay
together.

### Behaviour

Unchanged for a terminal user: same six frames, same `✶ Working (0s)` text,
same `(int) floor` elapsed seconds, same 0.08s throttle, same removal on the
first text chunk, on an empty response and at the end of a turn. No test file
was edited.

### Verification

`composer stan` — clean, level max, both passes.

`composer test` — 44 tests, 167 assertions, green.

`NeuronCliTest::testHostApplicationCanCustomizeConversationBranding` flaked
during the run. It was measured rather than assumed: the base commit's tree
was extracted to a scratch copy and given five unrelated dummy tests, where
the same test failed with none of this ticket's source changes present, and
the failure came and went between identical runs (40 tests failing, 41–44
passing). The suite on this branch then ran green four times in a row. The
flake is pre-existing, load-sensitive, and not caused by this work.

Also of note for whoever runs the suites next: PHPStan's cache directory
`/tmp/phpstan` is shared by every worktree of this repository, and a cache
written by another worktree makes `composer stan` fail with an internal error
pointing at that worktree's `phpstan.phar`. `rm -rf /tmp/phpstan` fixes it.

### One entry added to the glossary

`CONTEXT.md` gained **Working indicator**, since the concept now has a module
named after it and the code was calling it `loading` in one place and
`working` in another.
