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

**Status:** done

- [x] The `Internal` namespace segment no longer appears anywhere
- [x] Modules are grouped by vocabulary: History, Conversation, Tui
- [x] Every non-public class keeps its `@internal` annotation
- [x] The README states that the Neuron CLI module is the only public one
- [x] A static-analysis rule fails the build if the examples import anything but
      the public module
- [x] Static analysis passes at the configured level
- [x] The existing test suite passes **without a single test being edited**

## Comments

### Decision: which vocabularies get a namespace today

All four internal classes moved to `NeuronCli\Tui`. `History` and
`Conversation` have no directory yet, for the same reason `Session` has none:
a namespace appears when a module speaks its vocabulary, and today no class
does.

Every one of the four owns Symfony TUI widgets, heights, or scrolling. The
History vocabulary is inside `ConversationView::showExistingHistory()` and the
correlation half of `ToolActivity`; the Conversation vocabulary is inside
`NeuronCli` itself. Extracting either is 02–04 and 08, not this ticket, and
splitting them here would have been the scope creep the spec's Sequencing
section warns against.

Placing a class in `History` before its widgets are gone would have made the
directory name assert a boundary the code breaks — the spec's one-way rule is
"History does not know Symfony TUI". A truthful `Tui` today is worth more to
the tickets that follow than three directories, two of them lying.

The consequence, recorded so it is not rediscovered: `Tui\ConversationView`
and `Tui\ToolActivity` still import Neuron AI, so the one-way rule is stated
but not yet enforceable. It becomes enforceable once 02–04 land.

### The stability promise

The `@internal` annotations were already on all four classes and stayed. The
README gained a paragraph naming `NeuronCli\NeuronCli` as the only public
module.

Static analysis enforces it on the examples through `phpstan-examples.neon`,
which `composer stan` runs as a second pass. Two services share one policy:
`PublicModuleOnlyRule` reads `use` and group-use statements, so it catches an
import that is never used afterwards and keeps working when the examples'
own vendor directory is absent; `PublicModuleOnlyExtension` catches every
other mention of a class name, including a fully qualified `new`.

### Verification

`composer test` — 21 tests, 113 assertions, no test file edited.
`composer stan` — clean, level max on `src`, `tests` and `tools`.
