# 04 — The directory is declared, never guessed

**What to build:** A Host Application that wants Sessions kept on disk says
where. The file provider takes its directory as a required argument and no
longer invents one under the working directory, so a conversation can never
land somewhere nobody named. Together with the in-memory default, this leaves
the Host Application as the only thing that ever decides where conversations
live.

Everything else about the file provider is unchanged: keys minted as random
hex, files named by Neuron AI's own `FileChatHistory`, listing by reopening
each conversation through Neuron AI and asking the file only when it was last
written.

**Blocked by:** 03 — The default provider asks for nothing.

**Status:** resolved

- [x] `FileSessionProvider` requires a directory; the project-relative default
      and the working-directory lookup are gone
- [x] The test asserting that Sessions live under the project is replaced by
      one asserting the directory is required
- [x] The rest of the file provider's coverage carries over unchanged, still
      against a temporary directory with real `FileChatHistory` instances
- [x] The example application passes an explicit directory, and that directory
      is ignored by git — which also covers the untracked one left over from
      manual runs
- [x] The README's Sessions section documents the in-memory default, the
      required directory and the three operations, and no longer mentions a
      default location

## Answer

`DEFAULT_DIRECTORY` and the `getcwd()` fallback are gone; the constructor is
`__construct(private string $directory)`. `examples/demo.php` passes
`__DIR__ . '/sessions'`, and `examples/.gitignore` ignores both `/sessions/`
and the `/.neuron/` an earlier manual run could have left behind. The README's
Sessions section already said all of this: ticket 03 rewrote it, so no wording
changed here.

Verified in the worktree: `composer stan` clean on both analyses, `composer
test` green at 100 tests, 323 assertions. Note for whoever runs PHPStan across
worktrees — its result cache under `/tmp/phpstan` is shared and keeps a path
to whichever worktree wrote it, so a deleted sibling makes it fail with a
missing stub; a private `TMPDIR` avoids it.

Reviewed on both axes. One reservation, recorded rather than acted on: the
replacement test asserts the constructor signature through `ReflectionClass`,
which is structure rather than behaviour. It is the only form the requirement
has — PHP and PHPStan already refuse `new FileSessionProvider()`, so there is
no runtime harm left to observe — and the ticket asks for the test by name, so
it stays.
