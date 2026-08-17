# 06 — `/sessions` lists and resumes

**What to build:** A person can find a conversation they had earlier and pick
it up. Typing `/sessions` shows the Sessions of this Agent, most recent first,
each labelled with when it was last used and how the conversation opened.
Arrow keys move through the list, typing narrows it, Enter resumes the chosen
Session, and Escape leaves everything as it was.

Resuming paints that Session's History — with the same things hidden as
always — puts the view at the newest message, and the Agent answers with that
conversation's context.

While the list is open, the TUI is in the Session picker: the composer takes no
text and the arrow keys belong to the list.

**Blocked by:** 05 — `/clear` opens a new Session

**Status:** resolved

- [x] The store lists Sessions, most recent first, each with the time it was
      last used and a title taken from the first message the person wrote
- [x] The file adapter derives labels by reopening a conversation through
      Neuron AI, not by parsing stored data by hand
- [x] A Session that never received a message does not appear
- [x] The picker supports arrow navigation, type-to-filter and a bounded
      visible height
- [x] While the picker is open the composer accepts no text
- [x] Escape closes the picker with the current Session unchanged
- [x] Choosing a Session installs it on the Agent and repaints its History,
      hiding system messages, reasoning and raw payloads exactly as elsewhere
- [x] After resuming, the view sits at the newest message and the composer is
      empty
- [x] The Agent's next answer uses the resumed Session's context
- [x] `/sessions` is refused while the Agent is working, with the reason shown

## Comments

**A conversation held before the first `/clear` is still not listable.** The
TUI opens on the History the Agent arrived with, and only a Session change
installs a store Session, exactly as 05 left it. Replacing the configured
History at startup — ADR 0001 and user story 26 — was ruled out of this ticket
by the effort, so `/sessions` lists what the store actually holds and nothing
more. The limitation is known and deliberate, not worked around here.

**The seam grew by one operation and one value.** `SessionStore::list()`
returns `SessionSummary` — key, last used, title — and both are published in
`tools/phpstan/PublicModulePolicy.php`, since a Host Application implementing
the store has to name them. `FileSessionStore` reopens each conversation
through `FileChatHistory` and reads its opening through the History
projection; the file itself is asked one thing only, when it was last written,
which is the one fact the conversation does not carry.

**"The first thing the person wrote" is a History rule, so it lives in the
projection.** `HistoryProjection::openingWords()` is used by the file store and
by the in-memory test store alike, and titles therefore hide and redact
exactly what the painted History does.

**Filtering is prefix-only and matches the shortened title.** Symfony TUI's
select list filters with `str_starts_with` on an item's value, and the value
carries the title as it is shown — cut to the width the list gives a label.
Typing a word from the middle of a title finds nothing. This is the widget's
own behaviour, which the spec chose deliberately; the picker only feeds it the
typed characters, which the widget does not listen for itself.

**Three small things nobody asked for.** `/sessions` with an empty store says
so in the conversation rather than opening an empty list; the picker carries a
line of instructions that echoes the filter as it is typed; and the stylesheet
gained the four `.session-list::*` rules the list needs to be legible.

**While the picker is open, page keys stay with the list.** `NeuronCli` no
longer scrolls the History on PageUp/PageDown when the TUI is in the Session
picker, so every key that moves through the list belongs to it.

Verified with `composer stan` and `composer test` from the worktree: 98 tests,
308 assertions, no static-analysis errors. The existing terminal-level tests
were not edited.
