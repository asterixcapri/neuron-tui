# 03: Persist History through storage

**What to build:** Allow a Session's History to round-trip through shared storage while continuing to behave like Neuron AI History for messages, trimming and token usage.

**Blocked by:** 02: Provide shared storage.

**Status:** ready-for-agent

- [ ] A storage-backed History extends Neuron AI's abstract History and supplies only storage-backed loading, saving and clearing behaviour.
- [ ] Messages written through one History instance are deserialized with their supported content intact when another instance opens the same namespace/key.
- [ ] Replacing, trimming and clearing History update the stored JSON document consistently, including an empty History.
- [ ] Neuron AI remains responsible for message representation, deserialization, trimming and usage calculation while the storage adapter owns JSON encoding; no Host-supplied History implementation or factory is introduced.
