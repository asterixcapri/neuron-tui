# Sessions replace the Agent's chat history

Neuron AI already persists a conversation: `FileChatHistory`, `SQLChatHistory`
and `EloquentChatHistory` save, reload and deserialize messages, and reopening
one is just constructing it again with the same key. What Neuron does not
offer is a way to find out **which** keys exist — the key is a constructor
argument on each adapter, and `ChatHistoryInterface` has no listing operation.

So Neuron TUI owns a **Session provider** seam with three operations — start a
Session, list the Sessions, and resume one by key — and switches Session by
calling `Agent::setChatHistory()` with the History the provider returns. The
provider mints the key and opens the empty History together when a Session is
started; the caller never has to return a newly minted key to the provider.
A provider builds every History it hands back, so starting or resuming a
Session replaces the History the Host Application configured on the Agent.

The provider is therefore where a Host Application says where its conversations
live, and it is the Host Application that says it. The shipped no-configuration
provider, `InMemorySessionProvider`, takes nothing: it keeps its Sessions in
memory for the life of the process, which is the promise Neuron AI already
makes to an Agent given no History at all. Nothing is written anywhere until a
Host Application asks for it by passing a provider — `FileSessionProvider` over
Neuron's own `FileChatHistory`, or its own adapter over SQL or Eloquent. That
second adapter is what makes the seam worth having.

## Consequences

- The History the Host Application configured on the Agent **is replaced** once
  a Session is started or resumed. It is replaced by the provider the Host
  Application passed, so a Host that wants its own storage under the TUI passes
  the provider that reaches it, and a Host that passes nothing loses nothing it
  had asked to keep.
- A Host Application that configures nothing writes nothing: no directory, no
  file, and Sessions that end with the process.
- `flushAll()` is never called. On a persistent History it deletes the stored
  conversation rather than archiving it, so "new empty Session" is always a new
  key, never a flush.
