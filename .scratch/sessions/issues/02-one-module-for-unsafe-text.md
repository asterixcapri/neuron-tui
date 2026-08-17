# 02 — One module for unsafe text

**What to build:** Text that Neuron CLI does not control — an Agent's answer, a
tool's arguments and result, a queued message, a file name — is made safe to
display in one place instead of four. One module with two operations: make text
safe, and make a safe single-line preview of bounded width. Nothing a terminal
user sees changes; what changes is that there is one rule instead of four
copies of the same call, and it can be tested on its own.

The file-name placeholder stays a History rule — it decides what a person is
told about an attachment, not how text is typeset.

**Blocked by:** 01 — Module layout without the Internal segment

**Status:** done

- [x] One module offers exactly two operations: safe text, and safe single-line
      preview of bounded width
- [x] All four existing call sites go through it; no other copy of the
      sanitizing call remains in the codebase
- [x] The module is named for what it does — making untrusted text displayable
      — not for redacting secrets
- [x] Direct tests cover control bytes, invalid UTF-8, whitespace collapsing,
      and truncation at the display width, without a terminal
- [x] The file-name placeholder remains a History rule
- [x] The existing test suite passes without a single test being edited

## Comments

### The module

`NeuronCli\Tui\DisplayableText` offers `safe()` — the text with invalid UTF-8
and terminal-escape introducers gone, its line structure intact — and
`preview($text, $width)` — the same text collapsed to one line and truncated
to a display width. Nothing else. The width is the caller's decision, not the
module's, because the two callers want different ones.

It lives in `Tui` for the reason ticket 01 gave: a directory names the
vocabulary a module speaks, and bounded display width is the terminal's
vocabulary. It is also the only sensible home while Symfony TUI's
`StringUtils` is the thing doing the byte-level work.

### The four call sites

`NeuronCli::respond()` and `ConversationView::showQueuedMessages()` want
`safe()`; `ToolActivity` and `ConversationView::filePlaceholder()` want
`preview()`. `ToolActivity`'s private `safePreview()` helper is gone — it was
the fourth copy — and its `DETAIL_WIDTH` is now passed at each call rather
than bound by a delegating wrapper, which keeps the two `preview()` callers
the same shape.

`StringUtils` is no longer imported anywhere in `src/`. The one remaining
`mb_strimwidth` in the repository is in `examples/prototype-sessions.php`,
the throwaway prototype the spec already earmarks for removal; it is a bare
title truncation, not a copy of the sanitizing call.

### The file-name placeholder

It stays in `ConversationView`, unchanged in what it decides: the `[File]`
fallback and the basename are History rules. Only the typesetting moved. The
order is load-bearing and now carries a comment — sanitizing runs *before*
`basename()`, so a stripped escape sequence cannot forge the separator the
split happens on, and the collapse/truncate runs after. The extra `safe()`
inside `preview()` is a no-op on already-safe text.

### Verification

`composer stan` — clean, both passes. `composer test` — 33 tests, 125
assertions; the 12 new ones are all in `tests/Tui/DisplayableTextTest.php`
and no existing test file was touched.

One caveat, which needs stating precisely because a first, wrong measurement
of it is easy to make.

`NeuronCliTest::testHostApplicationCanCustomizeConversationBranding` fails
intermittently — always the same test, always with an empty virtual
terminal. It is the first test the suite executes, so it is the one paying
the cold-start cost of autoloading the whole Symfony TUI and Neuron AI
stack. Its `EventLoop::delay(0.05, …)` is registered *before* `NeuronCli` is
constructed, so the 50 ms timer is already running while those classes load.
When the load overruns the budget, the simulated Ctrl+C fires on the loop's
first tick and the TUI stops before painting anything.

Measured over ten full-suite runs each, on the same machine:

| source | test files | result |
| --- | --- | --- |
| base `9ff25b9` | existing only | 10 pass, 0 fail |
| this ticket | existing only | 10 pass, 0 fail |
| base `9ff25b9` | existing + a one-test dummy file | 9 pass, 1 fail |
| this ticket | existing + a one-test dummy file | 8 pass, 2 fail |
| this ticket | existing + `DisplayableTextTest` | 6 pass, 4 fail |

So the race is **pre-existing and lives in the existing test**, not in this
ticket's source: this ticket's source with no new test file is as stable as
the base. What makes it visible is the suite gaining a second test file at
all — an inert dummy with a single `assertTrue(true)` reproduces it against
untouched base source. The probability rises with how much the suite carries,
which is why twelve real tests trigger it more often than one dummy.

Not fixed here, deliberately: every available fix edits
`NeuronCliTest::testHostApplicationCanCustomizeConversationBranding`, and
this ticket's last acceptance criterion forbids editing an existing test.
It belongs to whichever ticket makes the terminal-level tests deterministic —
registering the delay after construction, or warming the autoloader, would
both do it. Until then any ticket that adds a test file will see this.

The earlier version of this note claimed the base commit flaked at the same
rate. That was measured through `git stash` while the crossing described
below was in effect, so the "base" being measured was not the base at all.
The table above was measured with `git checkout 9ff25b9 -- src`.

### A hazard worth knowing about

`git stash` is stored in the shared `.git` directory, so it is **common to
every worktree**. Using it to measure this ticket's changes against the base
commit crossed with a sibling ticket's agent stashing in its own worktree at
the same moment: each `pop` restored the other's work into the wrong tree.
Both were recovered from `git diff HEAD` patches and put back. Parallel
ticket agents should compare against a base commit with `git diff` or a
temporary commit, never with `git stash`.

### Noted for later

`CONTEXT.md` has no term for the concept this module names. "Displayable
text" or "unsafe text" may deserve a glossary entry — left for
`/domain-modeling` rather than decided in a refactoring ticket.
