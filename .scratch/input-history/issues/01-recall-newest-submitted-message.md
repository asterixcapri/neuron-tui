# 01: Recall the newest submitted Message

**What to build:** Record a non-blank Message submitted through the composer in persistent Input history. From an empty composer, Up recalls the newest stored value with the cursor at the end, giving a person an immediately reusable earlier input.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] Submitting a non-blank Message records its exact composer text through the configured Storage and Up recalls it from an empty composer with the cursor at the end.
- [ ] Up with an empty composer and no stored entries is a no-op.
- [ ] Input history is stored independently of the Agent's History and the Sessions namespace.
- [ ] Focused tests, static analysis and the complete test suite pass.
