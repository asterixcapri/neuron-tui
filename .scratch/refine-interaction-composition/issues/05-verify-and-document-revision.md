# 05: Verify and document the integrated interaction revision

**What to build:** A Host Application can follow the documented composition
examples against the revised packages, and the existing pull requests contain
a verified, coherent implementation of the agreed interaction contracts.

**Blocked by:** 02 — Retain slash identifiers throughout Command interaction;
04 — Preserve the initial History and explicit Session ownership. These transitively cover
tickets 01 and 03.

**Status:** completed

Parent: Refine Interaction composition and shared Commands, the spec in this
feature directory.

- [x] Run the full test suites and static analysis in Neuron Interaction and Neuron TUI after integrating all preceding tickets; resolve regressions within the revision's scope.
- [x] Validate Composer metadata and consumer dependency locks, including demonstration applications, against the revised shared package on the existing development branch.
- [x] Executable terminal and backend examples use the same shared Commands, slash identifiers and ordinary controls contract without terminal dependencies in Neuron Interaction.
- [x] Verify the complete public flow: optional module defaults and supplied-instance reuse, mutable Command mounting, Help and Leave during a Turn, slash-preserving selection reinvocation, startup preservation without automatic import, and recovery of explicitly managed Sessions after clear.
- [x] Retain coverage for optional InputHistory navigation, independent instance cursors/drafts, shared persisted inputs, unchanged execution outcomes and no automatic Command mounting.
- [x] Current usage documentation reflects module composition and the removal of Tui::addCommand, TUI-owned Storage configuration and concurrent Command abstractions. No current example relies on the removed APIs.
- [x] Reconcile architectural guidance with explicit, scoped supersession of ADR-0002, ADR-0003 and ADR-0005; preserve unrelated decisions and historical context. Keep CONTEXT a glossary, not an implementation checklist.
- [x] Record that slash identifiers and shared Help/Leave supersede the conflicting historical extraction requirements, without rewriting or reopening completed extraction tickets.
- [x] Verify interface names use the Interface suffix and the kit remains SessionCommandKit. Do not introduce an Interaction container, runtime collection locking, Agent cancellation or a compatibility layer.
- [x] Update existing Neuron TUI PR #11 and Neuron Interaction PR #1 with an accurate revision summary and validation results, crediting the human author alone.
- [x] No replacement branches or PRs, merges, releases or registry publication are performed. Do not close or change the parent spec as part of this ticket.

## Execution notes

Continue on feat/extract-neuron-interaction in both repositories. This is an
integration audit, not a reason to defer tests or usage documentation from the
behavior tickets. Prefer the already approved public API and virtual-terminal
test boundaries; do not add test-only architecture. Report actual validation
results and any remaining limitations rather than claiming unrun checks passed.

## Completion

Integrated implementation and documentation verified: 210 TUI tests (905
assertions), 111 Interaction tests (295 assertions), PHPStan in both repositories,
strict Composer validation for both packages and the demo, backend selection
example and local demo HTTP/streaming checks. Consumer locks resolve the final
shared package. Both existing PR descriptions have been updated: Neuron TUI
#11 and Neuron Interaction #1.

The code review found zero spec issues and one non-blocking simplification,
subsequently resolved. See [review](../review.md) and
[verification](../verification.md). The user's explicit History ownership
clarification is part of the canonical spec; its status remains unchanged.
