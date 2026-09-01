# 02: Store file Sessions as key-named JSON

**What to build:** Persist every new file-backed Session as `<key>.json`, then
list and resume only that convention. Keep opaque random keys and the existing
title behavior, while making the filename accurately describe the JSON it
contains.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] Starting and writing a file-backed Session creates one `<key>.json` file with no filename prefix.
- [ ] Stored JSON Sessions are listed in most-recently-used order and retain titles derived from the opening words.
- [ ] A JSON Session can be reopened by the same key and yields its stored History.
- [ ] Legacy `neuron_<key>.chat` files are not listed or resumed.
- [ ] No legacy file is renamed, migrated or deleted automatically.
- [ ] Random key generation and the Session provider interface retain their existing semantics.
- [ ] Provider tests cover creation, discovery, ordering, title projection, resumption and deliberate legacy incompatibility.
- [ ] The full test suite and static analysis pass.

