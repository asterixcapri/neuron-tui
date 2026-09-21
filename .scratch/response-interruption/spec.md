# Stop a streamed response through public Neuron interfaces

Status: implemented

Alternative to the complete Turn interruption on `feat/turn-interruption`.
This branch deliberately implements a narrower contract on Neuron 3.

- Escape with no Picker or Command suggestions open requests a response stop.
- A request waits until the current inference has displayable text. The first
  such chunk is retained when the request preceded all text.
- Stop only at a streamed text boundary; preserve partial content in an ordinary
  Assistant with `stop_reason=interrupted`, without synthetic Assistant text.
- Tools execute normally. A pending request does not skip later calls, change
  error handling, or prevent a following inference from starting.
- If interruption takes effect during text after tools, retain the completed
  group's real Tool results before the partial Assistant using ordinary messages.
- Natural completion wins if it occurs before the pending request is applied.
  Never replace, delete or replay committed History to relabel that outcome.
- A failure with no displayable text follows Neuron's ordinary failure behavior;
  no synthetic response or special History validation is introduced.
- Preserve draft/cursor, Escape precedence, FIFO queue advancement and Ctrl+C.
- Partial responses and their separate interruption notice survive Session reload.
- Production code must not subclass Neuron classes, use Reflection, access
  non-public members, replace a History/trimmer/executor, or wrap error handlers.

Validation: terminal/provider tests for text, deferred requests, tools, errors,
completion races, queue/draft/overlays, and persisted interrupted responses;
then formatting, static analysis and the complete test suite.
