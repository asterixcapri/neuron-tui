# 02 — One module for unsafe text

**What to build:** Text that Neuron CLI does not control — an Agent's answer, a
tool's arguments and result, a queued message, a file name — is made safe to
display in one place instead of four. One module with two operations: make text
safe, and make a safe single-line preview of bounded width. Nothing a terminal
user sees changes; what changes is that there is one rule instead of four
copies of the same call, and it can be tested on its own.

The file-name placeholder stays a History rule — it decides what a person is
told about an attachment, not how text is typeset.

**Blocked by:** 01 — Module layout without the Internal segment

**Status:** ready-for-agent

- [ ] One module offers exactly two operations: safe text, and safe single-line
      preview of bounded width
- [ ] All four existing call sites go through it; no other copy of the
      sanitizing call remains in the codebase
- [ ] The module is named for what it does — making untrusted text displayable
      — not for redacting secrets
- [ ] Direct tests cover control bytes, invalid UTF-8, whitespace collapsing,
      and truncation at the display width, without a terminal
- [ ] The file-name placeholder remains a History rule
- [ ] The existing test suite passes without a single test being edited
