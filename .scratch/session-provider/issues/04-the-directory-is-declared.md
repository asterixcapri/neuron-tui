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

**Status:** ready-for-agent

- [ ] `FileSessionProvider` requires a directory; the project-relative default
      and the working-directory lookup are gone
- [ ] The test asserting that Sessions live under the project is replaced by
      one asserting the directory is required
- [ ] The rest of the file provider's coverage carries over unchanged, still
      against a temporary directory with real `FileChatHistory` instances
- [ ] The example application passes an explicit directory, and that directory
      is ignored by git — which also covers the untracked one left over from
      manual runs
- [ ] The README's Sessions section documents the in-memory default, the
      required directory and the three operations, and no longer mentions a
      default location
