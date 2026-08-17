# 08 — The turn queue and the Agent turn, separated

**What to build:** What happens to a message typed while the Agent is working
is decided by a module that does nothing else: it holds the states of a turn,
the queue behind it, and the transitions between them. Consuming the Agent's
stream — text, tool calls, tool results, the empty answer — is a second module.

The line between them is the line between what can be verified without an event
loop and what cannot. Today three mutable fields encode the states without
naming them, and the transition that accepts a message and starts working is
written twice, once on submission and once when a turn settles.

What a terminal user sees does not change.

**Blocked by:** 01 — Module layout without the Internal segment

**Status:** ready-for-agent

- [ ] One module holds the turn's states, the queue and the transitions, with
      no input or output of its own
- [ ] The transition that accepts a message and starts working exists once
- [ ] A second module consumes the Agent's stream and decides when an answer
      was empty
- [ ] The queue is tested in memory, with no event loop and no provider
- [ ] The streaming module is tested with the fake provider
- [ ] Queued messages, their display, and the order they are sent in are
      unchanged from a terminal user's point of view
- [ ] The existing test suite passes without a single test being edited
