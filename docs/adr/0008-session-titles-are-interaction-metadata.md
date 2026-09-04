# Session titles are interaction metadata

A Session title identifies the same stored conversation in every interaction
Adapter. `Sessions` therefore derives it from the first non-empty user-authored
content in the Session History without depending on the TUI's
`HistoryProjection`.

The title remains presentation-neutral interaction metadata. A TUI escapes
and truncates it for terminal display, while a web frontend applies its own
rendering rules. This keeps terminal projection concerns out of Neuron
Interaction and gives every Adapter the same Session identity.
