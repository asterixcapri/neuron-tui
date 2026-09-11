# Turn interruption stops before new work

A Turn interruption lets work already begun finish, because Neuron tools do not
share a cancellation contract, but starts no subsequent tool or Agent inference
for that Turn. A concurrently started tool batch counts as work already begun;
all of it may finish before the interruption takes effect.

An Agent response is different from a tool execution because it arrives as a
stream. The TUI stops consuming it at the next chunk boundary and retains the
text already received as an interrupted, partial response. This makes interruption
responsive during a long response without claiming that an indivisible tool was
cancelled.

A retained partial response is an ordinary Assistant message whose stop reason
is `interrupted`. The TUI presents that metadata as an interruption, while the
next Agent invocation receives only the text the Agent actually produced. No
synthetic interruption sentence is added to the Agent's words.

When interruption precedes the first response chunk, the History retains the
person's message with interruption metadata and invents no empty or synthetic
Assistant response. A later waiting message therefore follows it as another
message from the person.

Tool calls the Agent requested but the TUI prevented from starting receive an
explicit technical result saying they were not executed because the person
interrupted the Turn. Recording those outcomes preserves an honest, structurally
complete History instead of leaving unmatched tool calls or implying that an
external effect was cancelled.

If already-started work fails after interruption was requested, its real failure
is retained; interruption neither replaces nor conceals that outcome. Remaining
tool calls are still recorded as not executed, and the queue still advances.

Once the interrupted Turn has settled, the ordinary queue advances: its first
waiting message starts the next Turn automatically. Interruption therefore
redirects the conversation without discarding or pausing inputs the person had
already submitted.

Escape requests a Turn interruption only when no more specific interaction
owns it. An open Picker or Command suggestions consumes the first Escape to
close itself; with neither open, Escape requests interruption and leaves any
composer draft intact.

The TUI acknowledges the request immediately and says when it is waiting for
already-started work. Repeated interruption requests while it waits have no
additional effect.
