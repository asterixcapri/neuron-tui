# Conversation runtime implementation review

Reviewed on 2026-09-05 against the canonical [spec](spec.md) and its three tickets.

- Neuron TUI: `git diff 6a2d4b532e94ebc0cb9f4b7374cd4930f2a6346d...514f0b5dfae47e197c741a94633c36eb672457ca`.
- Neuron Interaction: `git diff 8d297c314f518adece6ff0d9e7f4476cf6576a11...d823bce842c16d69d9432d4b7ea98ca21d618021`.

## Standards

Standards review: no actionable findings.

Reviewed the TUI diff from `6a2d4b532e94ebc0cb9f4b7374cd4930f2a6346d` through `514f0b5`, and the Neuron Interaction diff from `8d297c314f518adece6ff0d9e7f4476cf6576a11` through `d823bce`.

Documented-standard violations: none found. The shared interface follows both repositories' `docs/coding-standards.md` interface naming rule. Changed vocabulary follows TUI `CONTEXT.md`; its additions remain glossary definitions. ADR-0006 and ADR-0007 explicitly record the revised interface and return contract, satisfying `docs/agents/domain.md`'s requirement to surface ADR conflicts. Commit messages comply with the authorship instruction in both `AGENTS.md` files.

Baseline smells: none warrant changes within this spec. The repeated four-collaborator Adapter construction is expressly required for fresh invocations, and introducing a factory or bundled runtime abstraction would contradict the selected design. Adapter delegation implements the actual shared environment contract alongside admission, reconciliation and deferred selection; it is not an unnecessary middle man. The common concurrency predicate removes the previous repeated type test. PHPDoc output generics support the two implemented Adapter outputs rather than speculative extension points.

This was a read-only standards review. Existing test and static-analysis results were supplied in the verification record; checks were not rerun.

## Spec

No findings on the Spec axis: no missing or partial requirements, scope creep, or incorrectly implemented requirements were identified in the reviewed two-package diff.

The shared dispatcher preserves first-match lookup, passes unknown executions directly to completion, returns null on refusal, captures Command exceptions, and leaves admission/completion exceptions outside that guard. TUI invocations use fresh Adapters with live runtime access and individual History baselines; reconciliation precedes failure presentation. Deferred choices retain their arguments, repeat admission, bypass Input history, and retain stopped/cancelled Picker behavior.

ConversationInput and Tui.run() implement the requested ownership and assembly changes while preserving the existing input and Turn logic. Both dependency installations, the backend/example migrations, responsibility descriptions, and ADR return-contract updates match the coordinated specification.

Reviewed implementation and regression coverage against the canonical spec and all three tickets. Existing reported full-suite and maximum-level static-analysis results were considered; no broad suites were rerun.

Standards: 0 findings, no worst issue. Spec: 0 findings, no worst issue.
