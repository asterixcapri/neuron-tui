# 02 — The Session picker carries Sessions

**What to build:** A person can return to a Session whatever they wrote to open
it. Today the picker packs the title and the key of a Session into one string
and keeps them apart with a null byte, so a title containing that byte confuses
the list. The picker carries the Sessions themselves instead: the one a person
chooses comes back whole, and Neuron CLI asks the provider to open it by its
key.

The key stops travelling through the view and a terminal widget, which are two
layers that cannot interpret it and cannot check it. It now leaves the provider
only inside a Session, and returns to the provider from the caller that
received it.

**Blocked by:** 01 — `SessionProvider` replaces `SessionStore`.

**Status:** resolved

- [x] The picker is given Sessions and hands back the chosen Session, not a
      packed string
- [x] No separator character is used to join a title to a key anywhere
- [x] A Session whose title contains the byte formerly used as a separator is
      listed with its real title and resumes correctly, covered at the terminal
      seam
- [x] Arrow navigation, type-to-filter, Enter and Escape behave exactly as
      before
- [x] The terminal-level tests pass with no assertion edited beyond the one
      added for the title above

## Comments

Implemented on `ticket/02-picker-carries-sessions`, on top of `8d62a16`.

- `SessionPicker::open()` keeps the Sessions it was given, each under a handle
  it mints (`session-<place>`), and `choose()` looks the handle up and hands
  the `Session` itself to whoever opened the picker. `ConversationView` passes
  a `Session` on, and `NeuronCli::resumeSession()` asks the provider to open it
  by its key. No key travels as text through the view or the widget.
- The null-byte separator is gone with the packing: a line's `value` names a
  Session, its `label` shows the title, and nothing joins the two.
- The list narrowed on `value`, which no longer holds a title, so the picker
  narrows itself — the same rule the widget applied (case-insensitive prefix
  on the title), and it leaves the list alone when a keystroke narrows
  nothing, which is what kept the selection where a person put it. Arrows,
  Enter and Escape still reach the widget untouched.
- `close()` now lets go of the Sessions it was holding, so nothing outlives
  the moment a person could still choose.
- One test added at the terminal seam: a Session whose first message carries a
  null byte is listed under its real title and resumes as itself. It passes
  against the base commit too — `DisplayableText::preview()` already stripped
  the byte before the packing saw it, so the hazard was latent rather than
  live. It stands as the guard the ticket asked for.
- No existing assertion was edited: the test diff is additions only.

Verification: `composer stan` clean (both configurations), `composer test`
green — 99 tests, 312 assertions.
