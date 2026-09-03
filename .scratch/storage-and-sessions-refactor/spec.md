# Shared storage and TUI-owned Sessions

Refactor persistence before adding Input history. The Conversation TUI owns
Sessions through one storage configured by the Host Application; Session
commands reach the live Sessions through Controls instead of carrying a
provider supplied by the Host.

## Outcome

The persistent composition is:

```php
$storage = new FileStorage($directory);

Tui::make($agent)
    ->setStorage($storage)
    ->addCommand(new SessionKit())
    ->run();
```

The Host Application neither constructs a Session provider nor installs a Chat
History on the Agent. Without `setStorage()`, the TUI uses in-memory storage and
writes nothing outside the process.

## Storage

Add `NeuronTui\Storage\StorageInterface` with exactly this persistence surface:

```php
interface StorageInterface
{
    public function read(string $namespace, string $key): ?string;

    public function write(
        string $namespace,
        string $key,
        string $value,
    ): void;
}
```

The value is opaque to the storage. The module owning a namespace owns its
serialization and keys. A missing value reads as `null`; a write replaces one
namespace/key value atomically.

Ship `InMemoryStorage` and `FileStorage`. `FileStorage` receives its root
directory explicitly, separates namespaces on disk, and prevents a namespace
or key from escaping that root. Do not add listing, deletion, transactions,
TTL, queries or remote adapters.

## Sessions

Replace `SessionProvider`, `FileSessionProvider` and
`InMemorySessionProvider` with one concrete `NeuronTui\Session\Sessions`
module constructed from `StorageInterface`.

`Sessions` retains the existing public behaviour:

- `start()` mints a new key and returns its empty `ChatHistoryInterface`;
- `list()` returns non-empty Sessions, most recently used first;
- `resume($key)` returns the History of a known Session and rejects an unknown
  key;
- Session title, last-used time and optional storage size retain their current
  user-facing meanings.

Maintain any key index required by `list()` inside the `sessions` namespace.
An empty Session remains absent from `list()`.

Add an internal storage-backed Chat History extending Neuron AI's
`AbstractChatHistory`. It supplies only loading, saving and clearing through
`StorageInterface`; message serialization, deserialization, trimming and usage
calculation continue to come from Neuron AI. No History factory or
`ChatHistoryInterface` implementation is supplied by the Host Application.

The Conversation Runtime constructs exactly one `Sessions` instance and
installs the History returned by `start()` on the Agent. `Controls::sessions()`
exposes that same instance to normal Commands. `ConcurrentControls` does not
expose it, preserving the rule that a running Turn cannot have its conversation
replaced.

`SessionKit`, `ClearCommand` and `ResumeCommand` no longer receive a Session
dependency at construction. They reach Sessions through Controls. Mounting the
kit remains explicit; the Conversation TUI still mounts no Commands on its own.

## Naming refactor

All project interfaces use the Symfony `Interface` suffix:

- `Command` becomes `CommandInterface`;
- `ConcurrentCommand` becomes `ConcurrentCommandInterface`;
- `CommandKit` becomes `CommandKitInterface`.

`SessionProvider` is removed rather than renamed. Apply the renames throughout
source, tests, examples, README and type documentation without compatibility
aliases.

Use **Command** as the canonical domain term while retaining `/` as its syntax.
Rename `SlashCommandInput` to `CommandInput`, rename corresponding methods and
messages, and replace prose references to “Slash command” with “Command”.

## Compatibility and boundaries

- Preserve the current fluent `Tui` construction and its freeze-on-run rule;
  `setStorage()` follows the other setters and rejects mutation after startup.
- Preserve command mounting, duplicate-name precedence, Picker behaviour,
  queuing and History rendering except where dependency signatures are changed
  above.
- Preserve key-named JSON Session payloads in file storage as required by
  ADR-0004. A migration layer for formats predating ADR-0004 remains out of
  scope.
- Input history and arrow-key behaviour are out of scope for this spec.

## Completion criteria

- The public composition shown under Outcome runs with file storage.
- Configuration without `setStorage()` runs entirely in memory and creates no
  files.
- Session start, list, resume, metadata, invalid-key and empty-Session behaviour
  are covered through `Sessions`, not through removed provider adapters.
- Normal Commands can reach the runtime's Sessions; Concurrent Commands cannot.
- No project-defined interface remains without the `Interface` suffix.
- Static analysis and the complete test suite pass.
