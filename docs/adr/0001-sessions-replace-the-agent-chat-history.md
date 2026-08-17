# Sessions replace the Agent's chat history

Neuron AI already persists a conversation: `FileChatHistory`, `SQLChatHistory`
and `EloquentChatHistory` save, reload and deserialize messages, and reopening
one is just constructing it again with the same key. What Neuron does not
offer is a way to find out **which** keys exist — the key is a constructor
argument on each adapter, and `ChatHistoryInterface` has no listing operation.

So Neuron CLI owns a **Session store** seam with two operations — list the
Sessions, and open one by key (or mint a new one) — and switches Session by
calling `Agent::setChatHistory()` with what the store returns. The default
adapter is file-based, over Neuron's own `FileChatHistory`, so a Host
Application that says nothing still gets `/clear` and `/sessions`. Hosts that
keep conversations in SQL or Eloquent supply their own adapter; that second
adapter is what makes the seam worth having.

## Consequences

- The History the Host Application configured on the Agent **is replaced** once
  the TUI starts. A Host that cares must pass a Session store that reaches the
  same place.
- `flushAll()` is never called. On a persistent History it deletes the stored
  conversation rather than archiving it, so "new empty Session" is always a new
  key, never a flush.
