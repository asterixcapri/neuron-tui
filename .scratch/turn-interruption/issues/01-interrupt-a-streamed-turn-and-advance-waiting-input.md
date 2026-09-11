# 01: Interrupt a streamed Turn and advance waiting input

**What to build:** Let a person press Escape during a streamed Agent response to
request a Turn interruption, retain the response received so far, and continue
automatically with the first waiting message. The interaction must acknowledge
the request immediately without erasing a composer draft or taking Escape away
from a Picker or Command suggestions.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] With no Picker or Command suggestions open, Escape during a streamed response requests interruption and immediately changes the visible status.
- [ ] The response stops at the next streamed event boundary and no later response content from that Turn is presented.
- [ ] Displayable text already received remains visible and is committed as an ordinary Assistant message with stop reason `interrupted`.
- [ ] The provider for the next Turn receives only the partial text the Agent produced, without a synthetic interruption sentence.
- [ ] When interruption precedes the first displayable response chunk, the original User message is retained with interruption metadata and no Assistant message is invented.
- [ ] The first waiting message starts the next Turn automatically; additional waiting messages retain FIFO order.
- [ ] With no waiting message, the Conversation TUI returns to its ready state.
- [ ] Escape preserves a non-empty composer draft while requesting Turn interruption.
- [ ] An open Picker or visible Command suggestions consumes the first Escape without interrupting the Turn; a later Escape with neither open requests interruption.
- [ ] Repeated Escape presses while interruption is pending do not duplicate History reconciliation or queue advancement.
- [ ] A race between natural completion and Escape produces exactly one terminal outcome and advances the queue at most once.
- [ ] Ctrl+C exit, ordinary Escape draft clearing outside a busy Turn, input recall, scrolling, concurrent Commands, and Neuron Workflow interruptions retain their existing behavior.
- [ ] Focused Conversation TUI, Turn-runner, queue, and History-presentation tests cover the behavior at existing seams.

