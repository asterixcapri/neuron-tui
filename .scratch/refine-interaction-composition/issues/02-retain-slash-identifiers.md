# 02: Retain slash identifiers throughout Command interaction

**What to build:** A Command has the same slash-prefixed identifier when it is
mounted, submitted, dispatched, displayed or reinvoked after a Selection
request. Invalid slashless configuration fails immediately.

**Blocked by:** None (can start immediately).

**Status:** completed

Parent: Refine Interaction composition and shared Commands, the spec in this
feature directory.

- [x] Mounted Command names include their leading slash. Constructor mounting and every currently available mounting entry point reject names missing it without automatic prefix insertion or removal.
- [x] Native identifiers are /help, /exit, /clear and /resume; configured aliases follow the same rule. Lookup remains exact, with no new case normalization or additional identifier grammar.
- [x] Submission parsing retains the slash in the Command name while preserving existing argument splitting and trimming. Direct shared dispatch preserves the CommandArguments supplied by its caller.
- [x] Help, suggestions, completion and error output display the actual identifier without adding a second slash.
- [x] Selection requests carry the actual slash-prefixed identifier, and the Picker's selected value successfully reinvokes Resume through the unchanged two-invocation protocol.
- [x] Ordered enumeration, first-match duplicate resolution and completed, unknown and failed execution outcomes remain unchanged.
- [x] Shared Commands tests cover valid names, rejected slashless names and exact lookup; existing Submission and virtual-terminal tests cover argument handling, suggestions, output and selection reinvocation.
- [x] All affected native Commands, custom example Commands and test fixtures in both repositories use the convention consistently. Backend examples do not depend on terminal prefix translation.
- [x] Package and consumer changes, including dependency locks, are integrated together; both repositories' relevant tests and static analysis pass.
- [x] Current usage documentation states the shared slash convention and distinguishes this revision from the historical extraction requirement for neutral identifiers.

## Execution notes

Use the existing feat/extract-neuron-interaction branches and existing PRs.
This ticket does not depend on moving Help and Leave: update them wherever
they currently live. It may overlap ticket 01 in presentation and fixtures;
coordinate integration rather than inventing a semantic dependency. Introduce
no slashless compatibility layer and do not reopen completed extraction tickets.

## Completion

Slash identifiers integrated in TUI 5428d85 and Interaction 3cd79b7. Shared ticket suite passed (108 tests); integrated checks passed (111 TuiTest tests, 47 Command tests, PHPStan in both repositories). Consumer locks resolve the integrated shared commit. Final full-suite audit follows in ticket 05.
