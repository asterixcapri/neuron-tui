# 02: Provide shared storage

**What to build:** Give the Host Application one small persistence abstraction that can keep opaque TUI-owned values either for the current process or under an explicitly selected directory.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] `StorageInterface` exposes exactly `read(namespace, key)` returning a nullable string and `write(namespace, key, value)` returning nothing; it exposes no listing, deletion, transaction, TTL or query operations.
- [ ] In-memory storage returns `null` for missing values, isolates namespace/key pairs and replaces an existing value on write without touching the filesystem.
- [ ] File storage receives its root explicitly, separates namespaces on disk, returns `null` for missing values and atomically replaces an existing value.
- [ ] File storage rejects every namespace or key that could escape or alias a location outside its configured root, with coverage for traversal and boundary cases.
