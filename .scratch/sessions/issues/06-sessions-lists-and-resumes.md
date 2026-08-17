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

**Status:** ready-for-agent

- [ ] The store lists Sessions, most recent first, each with the time it was
      last used and a title taken from the first message the person wrote
- [ ] The file adapter derives labels by reopening a conversation through
      Neuron AI, not by parsing stored data by hand
- [ ] A Session that never received a message does not appear
- [ ] The picker supports arrow navigation, type-to-filter and a bounded
      visible height
- [ ] While the picker is open the composer accepts no text
- [ ] Escape closes the picker with the current Session unchanged
- [ ] Choosing a Session installs it on the Agent and repaints its History,
      hiding system messages, reasoning and raw payloads exactly as elsewhere
- [ ] After resuming, the view sits at the newest message and the composer is
      empty
- [ ] The Agent's next answer uses the resumed Session's context
- [ ] `/sessions` is refused while the Agent is working, with the reason shown
