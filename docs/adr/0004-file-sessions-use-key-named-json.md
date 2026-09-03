# File Sessions use key-named JSON

File-backed Sessions are stored as `<key>.json`, because their contents are
JSON and the key already identifies the Session without a prefix. The key is a
logical Storage key; `FileStorage`, not Sessions, appends the physical `.json`
extension. The previous `neuron_<key>.chat` shape is deliberately not read or
migrated: keeping one storage convention is preferred over carrying
compatibility for an early file format.
