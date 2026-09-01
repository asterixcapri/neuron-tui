Status: ready-for-agent

# Recognizable resumable Sessions and JSON file storage

## Problem Statement

When a person opens `/resume`, every stored Session is represented only by its
title. Similar titles are difficult to distinguish, and the list omits useful
facts the Session provider already knows, especially when the Session was last
used. File-backed Sessions also use opaque `neuron_<key>.chat` names even though
their contents are JSON, and their storage size is not available to the Picker.

## Solution

Make each `/resume` choice recognizable at a glance. Keep the existing title,
derived from the opening words of the conversation, and add a compact detail in
the form `20 seconds ago · 29.8KB`. The time is always present; storage size is
shown only when the Session provider can report it. Do not show a Git branch.

Store new file-backed Sessions as `<key>.json`, with no prefix. Treat this as a
clean break: legacy `neuron_<key>.chat` files are neither listed, reopened nor
migrated.

## User Stories

1. As a person returning to an earlier conversation, I want every Session to show when it was last used, so that I can distinguish recent work from stale work.
2. As a person scanning many Sessions, I want the age shown as concise relative time, so that I can compare Sessions without parsing full timestamps.
3. As a person using `/resume`, I want the familiar opening words to remain the Session title, so that the list continues to identify conversations the same way.
4. As a person using file-backed Sessions, I want to see the stored size, so that I can recognize unusually small or large conversations.
5. As a person using a provider that cannot report storage size, I want the Session to remain selectable without a placeholder or error, so that optional metadata does not impair resuming.
6. As a person reading the Picker, I want time and size on one compact detail line, so that the title remains visually dominant.
7. As a person reading the Picker, I want metadata separated with a middle dot, so that distinct facts remain easy to scan.
8. As a person opening `/resume`, I want every relative age calculated against the same instant, so that Sessions near a time boundary are compared consistently.
9. As a person encountering a Session timestamp equal to the current time, I want to see `just now`, so that the list avoids awkward zero-second wording.
10. As a person encountering a future timestamp caused by clock skew or copied files, I want to see `just now`, so that the Picker never presents a negative age.
11. As a person returning after seconds, minutes, hours, days, months or years, I want the largest appropriate time unit, so that the age stays compact at every scale.
12. As a person reading a one-unit age, I want singular wording, so that values such as `1 minute ago` read naturally.
13. As a person reading a multi-unit age, I want plural wording, so that values such as `20 seconds ago` read naturally.
14. As a person reading Session sizes, I want compact binary-scaled units, so that byte counts do not overwhelm the Picker.
15. As a person reading an exact unit size, I want redundant trailing zeroes removed, so that `1KB` is shown instead of `1.0KB`.
16. As a person reading a non-exact unit size, I want at most one decimal place, so that values such as `29.8KB` are useful without becoming noisy.
17. As a Host Application author using the in-memory provider, I want Sessions to omit unavailable storage size honestly, so that an invented number is never presented as fact.
18. As a Host Application author implementing a custom Session provider, I want storage size to be optional, so that SQL, Eloquent and other adapters are not forced to simulate a file concept.
19. As a Host Application author using the file provider, I want each Session's reported size to match the actual persisted file, so that the value has a clear meaning.
20. As a Host Application author inspecting persisted Sessions, I want filenames to end in `.json`, so that tooling and people can recognize their content format.
21. As a Host Application author inspecting persisted Sessions, I want the key itself to be the filename stem, so that no redundant `neuron_` prefix obscures the identifier.
22. As a maintainer, I want the random key strategy to remain unchanged, so that readable titles do not introduce filename collisions, unsafe characters or privacy leakage.
23. As a maintainer, I want only one active file-storage convention, so that the provider does not accumulate legacy discovery and migration branches.
24. As a person with old `.chat` files, I expect them not to appear after this breaking storage change, so that the absence of compatibility is explicit rather than accidental.
25. As a person selecting a Session with the new metadata visible, I want Enter to resume exactly the selected History, so that richer presentation does not change Picker behavior.

## Implementation Decisions

- Extend the Session value exposed by providers with an optional storage size expressed as a non-negative byte count.
- Keep the Session key, last-used time and title semantics unchanged.
- Keep titles derived from the opening words of the conversation. Do not introduce manual or Agent-generated naming.
- Have the file Session provider report the actual size of each persisted Session file.
- Have the in-memory Session provider omit storage size because it has no persisted representation whose footprint it can report honestly.
- Allow custom Session provider adapters to omit storage size.
- Build each `/resume` Picker option from the Session title plus a formatted metadata detail.
- Capture the current time once when `/resume` opens and use it for every option in that Picker.
- Format present and future timestamps as `just now`.
- For past timestamps, use the largest applicable unit with thresholds of 60 seconds, 60 minutes, 24 hours, 30 days and 365 days.
- Keep relative-time wording in English and do not add localization or configuration.
- Join relative time and available size with ` · `. Show relative time alone when size is absent.
- Scale sizes by 1024 through `B`, `KB`, `MB` and `GB`, extending to larger units if needed to represent the byte count safely.
- Render sizes with at most one decimal place, remove a trailing `.0`, and place no space before the unit.
- Do not include Git branch metadata.
- Configure file-backed Histories to use an empty filename prefix and `.json` extension, producing `<key>.json`.
- Discover and resume only the new `<key>.json` convention.
- Do not list, resume, rename or migrate legacy `neuron_<key>.chat` files.
- Continue minting opaque random keys. A human-readable title must not become a filename or storage identifier.
- Concentrate relative-time and byte-size presentation behind one formatting module so `/resume` does not own threshold and rounding rules inline.
- Preserve existing Picker navigation, cancellation and Session-resumption behavior.
- Respect the accepted decision that file-backed Sessions use key-named JSON and deliberately carry no legacy compatibility.

## Testing Decisions

- Test observable behavior rather than private helpers or implementation structure.
- At the Conversation TUI seam, open `/resume` through the existing virtual-terminal path and assert that a Session title, relative age and available size are visible before choosing it. Then choose the Session and assert that its History is resumed.
- Keep the existing high-level `/resume` test as prior art for opening the Picker, capturing its display, selecting an option and verifying the Agent's resulting History.
- At the Session provider seam, verify that the file adapter writes and reopens `<key>.json`, lists Sessions in last-used order, reports the file's exact byte count and does not discover `.chat` files.
- At the Session provider seam, verify that the in-memory adapter returns no storage size while preserving listing and resumption behavior.
- Use existing file-provider tests as prior art for temporary directories, fixed file modification times, listing order, titles and resumption by key.
- Add a deterministic presentation seam for relative time and storage size. Pass an explicit Session and current instant so tests never depend on wall-clock timing.
- Cover `just now`, future timestamps, singular and plural values, and boundaries across seconds, minutes, hours, days, months and years.
- Cover byte formatting below 1KB, exact and fractional KB, MB and GB values, including removal of `.0`.
- Cover a Session with no storage size and one with a reported size, ensuring the middle dot appears only when both metadata values are present.
- Preserve the full PHPUnit suite and static-analysis checks as completion gates.

## Out of Scope

- Showing or storing a Git branch.
- Localizing relative-time text or size units.
- Letting a person rename a Session.
- Generating a Session title with an Agent or an additional model request.
- Deriving filenames from Session titles.
- Changing the random key format.
- Computing an estimated serialized size for in-memory, SQL, Eloquent or custom providers.
- Defining storage-size semantics for third-party adapters beyond the optional byte count they may report.
- Reading, listing, reopening, renaming or migrating legacy `.chat` files.
- Automatically deleting legacy `.chat` files.
- Changing the JSON message representation written by Neuron AI.
- Changing Picker navigation, filtering, cancellation or selection semantics.

## Further Notes

- The current file contents are already JSON; this change makes the extension describe the existing representation rather than introducing a new serialization format.
- Local legacy `.chat` files may remain on disk but become invisible to the provider. They are not deleted by this work.
- The domain glossary records storage size as optional Session recognition metadata.
- The accepted storage ADR records the intentional break from the old prefixed `.chat` convention.
- A preliminary uncommitted fix currently displays an absolute last-used timestamp. Implementation of this spec should replace it with the confirmed relative-time detail rather than layering another presentation on top.
