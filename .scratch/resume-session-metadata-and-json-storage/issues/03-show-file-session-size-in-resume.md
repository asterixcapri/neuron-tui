# 03: Show file Session size in `/resume`

**What to build:** Let a Session provider optionally report storage size and
show that size beside relative age in `/resume`. File-backed Sessions report
the real size of their JSON file; providers without an honest persisted
footprint leave it absent, so the Picker remains useful without invented data.

**Blocked by:** 01: Show relative Session age in `/resume`; 02: Store file Sessions as key-named JSON.

**Status:** ready-for-agent

- [ ] A Session can carry an optional non-negative storage size expressed in bytes without breaking providers that cannot report one.
- [ ] The file Session provider reports the exact byte size of each `<key>.json` file it lists.
- [ ] The in-memory Session provider omits storage size while preserving listing and resumption behavior.
- [ ] `/resume` renders relative time alone when size is absent and `relative time · size` when it is present.
- [ ] Sizes scale by 1024 through `B`, `KB`, `MB` and `GB`, with larger units supported if needed.
- [ ] Size text uses at most one decimal place, removes a trailing `.0` and has no space before the unit.
- [ ] Formatting tests cover bytes, exact and fractional larger units, absent size and separator behavior.
- [ ] A Conversation TUI test observes title, relative age and real file size, then chooses and resumes the same Session.
- [ ] No Git branch, localization, Agent-generated title or title-derived filename is introduced.
- [ ] The full test suite and static analysis pass.
