# Integrated verification

The agreed implementation preserves the Agent's chosen History without importing
it into Sessions or automatically resuming the latest Session. The Host explicitly
selects a managed History when it wants Session persistence.

- TUI full suite: 210 tests, 905 assertions, passed with the updated installed
  package. PHPStan passed. The final lookup simplification passed six focused
  tests in its worktree and four tests (46 assertions) in the integrated checkout.
- Interaction full suite: 111 tests, 295 assertions; PHPStan passed.
- Strict Composer validation passed for Interaction, TUI and the standalone demo.
- The executable backend example completed its slash-prefixed selection round trip.
- PHP syntax checks passed for the terminal example and its custom Command.
- Two-axis code review found no spec violations and one optional simplification,
  subsequently resolved; see [review](review.md).

## Demo HTTP dependency correction

The user reported `Class "Amp\Http\Client\HttpClientBuilder" not found` on the
first prompt from `examples/demo.php`. The same failure reproduced using the
demo autoloader and the HTTP builder. The package was absent from the demo's lock
and vendor directory: Neuron AI lists this optional transport as a development
dependency, while the demo explicitly chooses AmpHttpClient for its providers.

The demo now requires `amphp/http-client:^5.3` at runtime and its lock includes
that transport and dependencies. The original missing-class probe passes. A
loopback HTTP server verified both a POST to `responses` and streaming through
Neuron's actual AmpHttpClient. This check used no model endpoint or credentials.
The diagnostic harness is stored outside the repository at
`/tmp/neuron-refine-notes/http-smoke.py`; no debug instrumentation was added.

The behavior suites use fake providers and virtual terminals. No live model
request was made as part of these checks.
