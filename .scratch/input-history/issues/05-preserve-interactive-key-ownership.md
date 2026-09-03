# 05: Preserve interactive key ownership

**What to build:** Integrate Input history without taking keys from the Conversation TUI states that already own them or surprising a person with Command suggestions caused only by programmatic recall.

**Blocked by:** 02/Navigate and edit Input history; 03/Record every submitted input.

**Status:** completed

- [x] While a Command suggestion list is open, Up and Down continue to navigate that list rather than Input history.
- [x] While a Picker is open, all existing Picker key ownership remains unchanged.
- [x] Page Up and Page Down continue to scroll the conversation History and never navigate Input history.
- [x] Recalling a Command does not open Command suggestions; editing the recalled value or returning to the empty composer restores ordinary suggestion rules.
- [x] Existing suggestion, Picker, scrolling, submission and queue coverage remains green together with the complete test suite and static analysis.
