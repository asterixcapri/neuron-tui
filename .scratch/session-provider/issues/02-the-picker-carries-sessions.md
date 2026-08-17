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

**Status:** ready-for-agent

- [ ] The picker is given Sessions and hands back the chosen Session, not a
      packed string
- [ ] No separator character is used to join a title to a key anywhere
- [ ] A Session whose title contains the byte formerly used as a separator is
      listed with its real title and resumes correctly, covered at the terminal
      seam
- [ ] Arrow navigation, type-to-filter, Enter and Escape behave exactly as
      before
- [ ] The terminal-level tests pass with no assertion edited beyond the one
      added for the title above
