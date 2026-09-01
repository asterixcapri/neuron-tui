# 01: Enter runs the selected Command suggestion

**What to build:** When Command suggestions are open, Enter takes the selected
suggestion, completes its full Slash command name, and executes it immediately.
This works for both the first suggestion selected automatically and a different
suggestion selected with the arrow keys. Tab, ordinary composer submission,
the Picker, and `/resume` retain their existing behaviour. The visible controls
and documentation describe the resulting interaction accurately.

**Blocked by:** None (can start immediately).

**Status:** resolved

- [x] Enter executes the first Command suggestion selected automatically when
      the composer contains only a matching command prefix.
- [x] After arrow navigation, Enter executes the currently selected suggestion
      rather than the first match or the unfinished prefix.
- [x] The selected Slash command runs through the existing command dispatch
      path and receives no arguments when accepted directly with Enter.
- [x] Tab continues to complete the selected command name and leaves the
      composer open for arguments without executing the command.
- [x] When no selectable suggestion list is open, including when nothing
      matches or suggestions were dismissed, Enter keeps submitting exactly
      what is written.
- [x] Picker confirmation remains unchanged, including the Picker used by
      `/resume` to choose a Session.
- [x] Terminal-level tests cover the automatically selected suggestion and an
      arrow-selected suggestion through externally observable command results.
- [x] Existing tests covering Tab, unmatched input, ordinary messages, Picker
      choices, and Session resumption continue to pass.
- [x] Status text, user documentation, and implementation commentary no longer
      describe Enter as sending the incomplete draft while Command suggestions
      are open.

## Acceptance

Enter is recognized by the Command-suggestion listener only while a selectable
list provides a chosen name. The listener replaces the prefix with that full
name but leaves the key to the focused composer, so the existing submit,
parsing, and command-dispatch path executes it with empty arguments. With no
chosen name, Enter reaches that path unchanged. Tab and Picker key handling are
untouched.

Terminal-level coverage runs both the automatically selected first match and a
match selected with the arrow key, and proves that dismissed suggestions do
not replace the written draft. The full suite and static analysis pass.
