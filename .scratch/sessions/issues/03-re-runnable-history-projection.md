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

**Status:** claimed

- [ ] One module turns the Agent's messages into one ordered stream of entries
- [ ] A tool call is paired with its result inside the module, including when
      results arrive out of order or never arrive
- [ ] System messages and reasoning content never produce an entry
- [ ] Image, file, audio and video content produce a short placeholder; no raw
      payload and no unsafe file name can reach an entry
- [ ] The projection can be run at any moment, not only at construction
- [ ] Direct tests cover all of the above without starting a terminal
- [ ] The existing test suite passes without a single test being edited
