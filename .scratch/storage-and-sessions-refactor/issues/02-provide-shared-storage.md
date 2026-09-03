# 02: Provide shared storage

**What to build:** Give the Host Application one small persistence abstraction that can keep TUI-owned JSON documents either for the current process or under an explicitly selected directory.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] `StorageInterface` creates adapter-keyed JSON documents, reads and atomically writes their data and caller-owned string metadata by namespace and logical key, lists a namespace and idempotently deletes a document; it exposes no transaction, TTL or query operations.
- [ ] In-memory storage returns `null` for missing documents, isolates namespace/key pairs, orders entries by successful writes and replaces an existing document without touching the filesystem.
- [ ] File storage receives its root explicitly, separates namespaces on disk, owns JSON serialization and the `.json` extension, returns `null` for missing documents and atomically replaces an existing document.
- [ ] File storage rejects every namespace or key that could escape or alias a location outside its configured root, with coverage for traversal and boundary cases.
