# 01: Show relative Session age in `/resume`

**What to build:** When a person opens `/resume`, show when each Session was
last used as concise relative time beneath its existing title. Replace the
preliminary absolute timestamp with deterministic wording such as `just now`,
`20 seconds ago` or `2 hours ago`, without changing which Session Enter
resumes.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] Every `/resume` option keeps the title derived from the opening words and shows a relative last-used time as its detail.
- [ ] All options in one Picker opening are compared with one captured current instant.
- [ ] Present and future timestamps render as `just now`.
- [ ] Past timestamps use natural singular or plural wording and the largest applicable unit across seconds, minutes, hours, days, months and years.
- [ ] Unit boundaries follow 60 seconds, 60 minutes, 24 hours, 30 days and 365 days.
- [ ] Relative-time behavior is covered through a deterministic presentation seam rather than wall-clock-dependent tests.
- [ ] The Conversation TUI test opens the Picker, observes the relative detail, chooses the Session and verifies that its History is resumed.
- [ ] The full test suite and static analysis pass.

