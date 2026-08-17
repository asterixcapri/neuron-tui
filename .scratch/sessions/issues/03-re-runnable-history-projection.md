# 03 — A History projection that can run at any moment

**What to build:** The rules that decide what a person sees of the Agent's
History move out of the module that owns the widgets. One module turns the
Agent's messages into a single ordered stream of entries — the person's
messages, the Agent's messages, and tool activity, already in the right order
and already correlated. Pairing a tool call with its result is its business,
not something callers arrange between themselves.

It can be asked for that stream at any moment. Today the History is painted
once, in the constructor, which is the reason returning to a Session is
impossible.

What a terminal user sees does not change. What changes is that the most
sensitive rules in the project — which messages are hidden, what a raw payload
is replaced with — stop being verifiable only by scraping ANSI from a running
terminal.

**Blocked by:** 02 — One module for unsafe text

**Status:** done

- [x] One module turns the Agent's messages into one ordered stream of entries
- [x] A tool call is paired with its result inside the module, including when
      results arrive out of order or never arrive
- [x] System messages and reasoning content never produce an entry
- [x] Image, file, audio and video content produce a short placeholder; no raw
      payload and no unsafe file name can reach an entry
- [x] The projection can be run at any moment, not only at construction
- [x] Direct tests cover all of the above without starting a terminal
- [x] The existing test suite passes without a single test being edited

## Comments

### The projection

`NeuronCli\History\HistoryProjection::entriesFor($messages)` returns the
`list<Entry>` a person is meant to see. An `Entry` is an `EntryKind`
(`Person`, `Agent`, `Tool`) and the text; it holds no widget, no style and no
scroll position, so every rule it applies is assertable on plain data.

It keeps nothing between runs — the state that pairs calls with results lives
on a private instance created per call — so asking for the stream a second
time, or for a different Session's stream in between, gives the same answer.
That is the whole of "can be run at any moment": the rules no longer sit in a
constructor.

`ConversationView::showExistingHistory()` is now `showHistory()`, and it
clears the pane and drops the streamed-message and working-indicator handles
before painting, so calling it twice replaces the History rather than
appending to it. Ticket 04 had left that wrapper to the Sessions ticket; it is
here because this ticket's fifth criterion is only observable through it, and
it is three behaviour-neutral lines (the pane is empty at construction). **05
and 06 should call `showHistory()`, not add it.**

### Pairing

`NeuronCli\History\ToolCorrelation` holds the one rule: a call id identifies a
call exactly, whichever order results come back in; without a call id, calls
of the same name are answered in the order they were made; a result that
matches nothing is reported unmatched, and both callers then open the call it
should have answered and close it at once.

The first draft duplicated that rule — once by entry position in the
projection, once by widget handle in `Tui\ToolActivity` — which is exactly the
drift the ticket says pairing should not be subject to. Correlating positions
rather than handles let both use it: `ToolActivity` now keeps its notes in a
`list` and indexes into it. Its `spl_object_id()` bookkeeping and its own
call-id map are gone.

### What a person sees

Unchanged, deliberately, including the parts that look odd:

- Historical tool activity still ends with `Done in <1s`. The base produced it
  by calling `start()` and `finish()` back to back on the same tool, so the
  measured wait was ~0. The projection says the same thing explicitly through
  `NOTHING_WAS_WAITED_FOR`, because stored messages carry no timings and a
  call already filed beside its result is read in one instant. Worth
  revisiting when a resumed Session makes it visible on old conversations —
  but changing it here would have broken the ticket's first promise.
- `● name inputs`, `⎿ result`, `[Image]`, `[File: name]`, `[Audio]`,
  `[Video]`, the `\n\n` join between blocks, the `❯`/`●` speakers and the
  `user`/`agent`/`tool` styles are all byte-identical to the base.

### Tool text

`NeuronCli\History\ToolActivityText` renders a call as pending or completed
and is used by both the projection and the live pane, so live activity and
activity read back out of a stored Session cannot drift. `Tui\ToolActivity`
keeps only the painting.

### Known wart, inherited

`History` imports `Tui\DisplayableText`, and `Tui\ToolActivity` imports from
`History`, so the two namespaces now reference each other. The spec's one-way
rule is "History does not know Symfony TUI"; that still holds — no widget, no
`Symfony\Component\Tui` import in `src/History` — but the tidy layout it
implies does not, and cannot until `DisplayableText` moves out of `Tui`.
Ticket 02 put it there deliberately and this ticket may not edit its test, so
the alternative was two copies of the redaction rule. One rule with an untidy
edge beat two rules.

Also noted, as ticket 02 noted before: `CONTEXT.md` has no term for **History
projection** or **Entry**, both of which the spec itself uses. Left to
`/domain-modeling` rather than decided here.

### Verification

`composer stan` — clean, both passes, level max. `composer test` — 55 tests,
187 assertions, green; 16 of them new, in `tests/History/
HistoryProjectionTest.php` and `tests/Tui/ConversationViewTest.php`. No
pre-existing test file was edited.

The known flake — `NeuronCliTest::
testHostApplicationCanCustomizeConversationBranding`, documented in ticket 02
— was measured here at 23 of 24 full-suite runs green. An earlier round of 8
runs failed 6 times, but at a load average near 8: a sibling worktree's suite
was running concurrently, and the test's 50 ms cold-start budget loses to CPU
contention. Under 1.0 load it did not reproduce beyond the documented rate.
Nothing in this ticket's source affects it: with only the pre-existing test
files selected, 6 of 6 runs were green.
