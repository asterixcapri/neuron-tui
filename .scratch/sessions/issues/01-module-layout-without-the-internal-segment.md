# 01 — Module layout without the Internal segment

**What to build:** Nothing changes for anyone using Neuron CLI. Internally, the
modules are regrouped by the vocabulary they are allowed to speak — the
projection of the Agent's History, the conversation's turns and input, the
Session store, and the terminal widgets — and the `Internal` namespace segment
disappears, because it names a stability promise rather than a concept. The
promise moves to where tools actually read it: `@internal` annotations, a line
in the README, and a static-analysis rule.

This is the prefactor the rest of the work stands on: it makes the following
tickets easy, and on its own it must be invisible.

**Blocked by:** None — can start immediately.

**Status:** ready-for-agent

- [ ] The `Internal` namespace segment no longer appears anywhere
- [ ] Modules are grouped by vocabulary: History, Conversation, Tui
- [ ] Every non-public class keeps its `@internal` annotation
- [ ] The README states that the Neuron CLI module is the only public one
- [ ] A static-analysis rule fails the build if the examples import anything but
      the public module
- [ ] Static analysis passes at the configured level
- [ ] The existing test suite passes **without a single test being edited**
