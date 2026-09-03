# Shared storage underlies TUI state

Sessions and Input history are concrete behaviour modules over one
`StorageInterface`, whose complete persistence contract is reading and writing
an opaque value by namespace and key. `InMemoryStorage` and `FileStorage`
provide transient and persistent adapters; further adapters belong to Host
Applications when a real need arises.

Sessions bridge the Agent to the same storage with an internal Chat History
that extends Neuron AI's `AbstractChatHistory`, retaining Neuron's message
serialization, deserialization, trimming and usage behaviour while supplying
only storage-backed loading and saving. This supersedes ADR-0001's separate
Session provider seam: a Host Application configures one storage, and neither a
History factory nor a second persistence adapter is exposed in its composition.
The Conversation Runtime constructs one `Sessions` instance from that storage,
starts the initial Session and installs its History on the Agent. Normal commands
reach that same instance through Controls; Concurrent commands cannot reach it,
because changing Session while a Turn is running would move the conversation
out from under the answer in flight.

## Consequences

- `SessionProvider`, `FileSessionProvider` and `InMemorySessionProvider` are
  replaced by the concrete `Sessions` module.
- Session commands carry no provider of their own; the Host Application can
  mount `SessionKit` without constructing or passing Session state.
- Input history and Sessions share one storage without sharing their domain
  behaviour or persisted namespaces.
- The storage interface does not expose listing, deletion, transactions or
  domain-specific operations; modules maintain any indexes they require.
