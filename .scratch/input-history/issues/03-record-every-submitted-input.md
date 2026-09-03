# 03: Record every submitted input

**What to build:** Make every non-blank composer submission recallable before the Conversation TUI decides whether it is a Message or Command, without changing how any submission is dispatched.

**Blocked by:** 02/Navigate and edit Input history.

**Status:** ready-for-agent

- [ ] Recognized Commands, unknown Commands and Commands refused while a Turn is running are recorded and can be recalled in submission order.
- [ ] A queued Message becomes recallable as soon as it is submitted, while remaining in the queue under the existing queue rules.
- [ ] Blank submissions create no entry and cause no Storage write.
- [ ] Message submission, Command dispatch, unknown and refused Command handling, and queue behaviour remain unchanged.
- [ ] Focused tests, static analysis and the complete test suite pass.
