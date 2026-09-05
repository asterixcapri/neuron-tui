# Shared storage underlies TUI state

_The Refine Interaction composition revision supersedes runtime-owned module
construction and a single TUI Storage configuration below. The Host Application
may supply Commands, Sessions and InputHistory independently. Tui creates omitted
modules once: empty Commands and in-memory Sessions and InputHistory. Runtime and
Command controls reuse those modules. Persistence is configured through the
supplied modules; they may share Storage but are not required to. The concurrency
controls policy is superseded as recorded in ADR-0002. Storage contracts and
namespace separation remain unchanged. The same revision also supersedes the
unconditional replacement of the Agent's initial History: startup displays that
History unchanged, without importing it into Sessions or selecting a latest
Session. The Host Application explicitly installs a History from the configured
Sessions with start() or resume(key) when it wants the initial conversation to
be resumable. Clear starts a managed Session; Resume lists only conversations
managed by the configured Sessions. This applies to default in-memory Sessions
too. No retention/import API or runtime snapshot state is introduced. Normal
Session trimming, title rules and the single-run TUI lifecycle remain unchanged._

_The historical decision text follows; apply the scoped supersessions above.
The shared kit is now named SessionCommandKit._

Sessions and Input history are concrete behaviour modules over one
`StorageInterface`. Storage persists JSON documents by namespace and logical
key, keeping caller-owned string metadata atomic with their data. JSON byte
size is derived by the document itself. Serialization, physical filenames and
discovery belong to the storage adapter rather than to the modules using it. `InMemoryStorage`
and `FileStorage` provide transient and persistent adapters; further adapters
belong to Host Applications when a real need arises.

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
- Storage exposes namespace listing and idempotent deletion because document
  discovery and lifecycle are persistence concerns. Callers own metadata and
  interpret and order documents. Storage does not expose transactions, queries
  or domain-specific operations.
- File storage appends its `.json` extension internally. Logical keys never
  contain a storage-format suffix.
- Storage adapters generate opaque keys when creating new documents; domain
  modules do not manufacture persistence identifiers.
- Sessions persist their last-used time as document metadata; they do not
  interpret adapter-specific file, database or object timestamps.
