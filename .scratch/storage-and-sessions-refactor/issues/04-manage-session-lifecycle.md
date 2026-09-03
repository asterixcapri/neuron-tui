# 04: Manage the Session lifecycle

**What to build:** Provide one concrete `Sessions` module over shared storage that starts conversations, discovers conversations worth showing and resumes a known conversation with the metadata people already use to recognize it.

**Blocked by:** 03: Persist History through storage.

**Status:** ready-for-agent

- [ ] `start()` uses the distinct opaque key minted by storage and returns its empty History; a Session remains absent from `list()` until its History is non-empty.
- [ ] `list()` returns non-empty Sessions most recently used first with the existing title, last-used time and optional storage-size meanings.
- [ ] `resume(key)` returns the persisted History for a known Session and rejects an unknown key without silently creating it.
- [ ] A new `Sessions` instance over the same storage can list and resume earlier Sessions through storage discovery, without maintaining a parallel Session index.
- [ ] File-backed Session payloads remain key-named JSON as required by ADR-0004, and formats predating ADR-0004 are neither read nor migrated.
- [ ] Session lifecycle, invalid-key, metadata, ordering, empty-Session and storage-size behaviour are covered through `Sessions`, not provider adapters.
