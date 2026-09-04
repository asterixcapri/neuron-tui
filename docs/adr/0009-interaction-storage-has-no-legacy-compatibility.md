# Interaction storage has no legacy compatibility

Neuron Interaction defines its persistence contract without preserving the
serialized documents previously written by Neuron TUI. The extraction ships
no legacy reader, format fallback or automatic migration for existing
Sessions or Input history.

This leaves the shared model free to choose storage documents around its own
boundaries. Existing Neuron TUI data may remain untouched on disk, but the new
package is not required to discover or interpret it.
