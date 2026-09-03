# 02: Navigate and edit Input history

**What to build:** Let a person move through several stored composer inputs after entering Input history from an empty composer, return to an empty composer at the newest bound, and adopt a recalled value as an editable draft without changing the stored entries.

**Blocked by:** 01/Recall the newest submitted Message.

**Status:** completed

- [x] Up moves toward older entries, repeated Up leaves the oldest selected, Down moves toward newer entries, and Down past the newest restores the empty composer and exits navigation.
- [x] Every recalled value places the cursor at the end; editing or submitting it exits Input history navigation without altering the stored entry.
- [x] Consecutive exactly identical submissions collapse into one entry while non-consecutive identical submissions retain their chronological positions.
- [x] A non-empty draft, including multiline and visually wrapped text, retains the editor's existing Up and Down behaviour and does not enter Input history.
- [x] Input history is loaded once for the Conversation Runtime, and arrow-key navigation performs no Storage reads or writes.
- [x] Focused tests, static analysis and the complete test suite pass.
