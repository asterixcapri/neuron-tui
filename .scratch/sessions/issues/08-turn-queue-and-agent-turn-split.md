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

**Status:** done

- [x] One module holds the turn's states, the queue and the transitions, with
      no input or output of its own
- [x] The transition that accepts a message and starts working exists once
- [x] A second module consumes the Agent's stream and decides when an answer
      was empty
- [x] The queue is tested in memory, with no event loop and no provider
- [x] The streaming module is tested with the fake provider
- [x] Queued messages, their display, and the order they are sent in are
      unchanged from a terminal user's point of view
- [x] The existing test suite passes without a single test being edited

## Comments

Implemented on branch `ticket/08-turn-queue`, base `c409a5a`.

- `NeuronCli\Conversation\TurnQueue` holds the states of a turn — named by
  `TurnState` (Idle, Accepted, Working) in place of the three unnamed mutable
  fields — the queue behind it and the transitions between them. It imports
  nothing, reads no input and paints nothing, so `tests/Conversation/TurnQueueTest.php`
  runs in memory with no event loop and no provider.
- Starting a turn is one transition on each side of the seam: `TurnQueue::start()`
  internally, and `NeuronCli::beginTurn()` for what a person sees. A message
  straight from the composer and a message that waited take the same path.
- `NeuronCli\Conversation\AgentTurn` consumes the Agent's stream — text, tool
  calls, tool results — and decides that an answer was empty from what was
  actually shown: displayable text that is blank and no tool activity.
  `tests/Conversation/AgentTurnTest.php` covers it with the fake provider.
- A turn is occupied from the moment a message is accepted, not from the moment
  the Agent receives it, which preserves the old `response || pendingInput`
  check. The promotion path keeps the previous call order verbatim, so queued
  messages, their display and their order are unchanged on screen.
- No existing test file was edited; the suite is green at 71 tests.
- `AgentTurn` depends on `ConversationView` and, through it, the working
  indicator: the spec's "Conversation does not know widgets" rule cannot hold
  for a module that paints as it reads. Noted rather than worked around; the
  same reach already exists from `Tui` into Neuron AI.
- `CONTEXT.md` gains a **Turn** entry for the vocabulary this module names.

Verification from the worktree root: `composer stan` (both configurations, no
errors) and `composer test` (71 tests, 231 assertions, green).
