# Experimental HTTP EOF response stop

Status: implemented; offline validation passed

Question: can the maintainer-endorsed HTTP EOF approach replace the TUI's manual
history finalization? Compare this branch with `feat/response-interruption` (#18),
not with a patch to Neuron. Keep both previous implementations intact.

## Contract

- Explicit Host opt-in: one `ResponseStop`, composed with the Host HTTP client,
  is also passed to the TUI. Never discover or replace an Agent's provider.
- Escape requests a cooperative EOF from one active HTTP stream. Keep consuming
  Neuron events so its normal persistence and Agent steps complete.
- No stop-related history writes, interrupted marker, custom trimmer, executor,
  provider subclass, reflection, or tool-error-handler override.
- Stop requests are idempotent and isolated per Turn. An old stream cannot
  consume a later Turn's request. Normal EOF may overtake a pending request.
- Preserve draft/cursor, FIFO and picker/suggestion precedence. No opt-in means
  the existing local Escape behavior; do not advertise unsupported cancellation.
- Preserve provider errors; do not reconcile failed partial streams manually.
- HTTP-stop notice is transient, while ordinary partial text is persisted by
  Neuron. An early EOF can produce an empty Assistant rather than user-only history.
- Tools continue normally; later inference may start. Do not claim that EOF
  safely cancels partially assembled tool calls across providers.
- Blocking reads/request setup are not forcibly interrupted. Underlying close
  semantics vary; AmpStream in Neuron 3.16.13 does not explicitly close its
  underlying readable stream. No remote cancellation or billing guarantee.

## Validation

- Real OpenAI Chat Completions, OpenAI Responses, Anthropic and Gemini parsers
  with HTTP fixtures: automatic partial history/Agent steps, reload and next Turn.
- Pre-text stop, natural completion, configuration forwarding, stale streams,
  sequential tools continuing, HTTP errors and reset between Turns.
- Real TUI/virtual-terminal Escape with HTTP fixtures: FIFO, draft cursor,
  suggestions and failures. Full existing suite without opt-in.
- Formatting, PHPStan and complete tests. No live API requests or credentials
  used by automated checks. A separate example allows manual live evaluation.

Source: https://inspector.dev/how-to-stop-a-streamed-ai-response-mid-flight-in-neuron-ai-v3/

Result: ordinary partial responses and Agent steps are finalized by Neuron with
no TUI history writes. 243 tests / 1120 assertions pass, including four real
provider parsers and terminal input exercised against deterministic HTTP fixtures.
PHPStan and formatting pass. Live transport/provider behavior remains a manual
experiment; existing #17 and #18 branches/PRs are unchanged.
