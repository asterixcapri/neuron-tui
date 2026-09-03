# 01: Adopt Command language

**What to build:** Make Command the canonical project term while retaining `/` as its input syntax. Rename the public Command interfaces with the `Interface` suffix and rename the interpreted Command input throughout the product, tests, examples and maintained documentation, without compatibility aliases.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] `CommandInterface`, `ConcurrentCommandInterface` and `CommandKitInterface` replace their unsuffixed predecessors throughout public types, implementations and type documentation.
- [ ] `CommandInput` and its corresponding methods and messages replace `SlashCommandInput` and prose that calls the domain concept a “Slash command”; `/` remains the syntax used to invoke a Command.
- [ ] The removed names have no compatibility aliases and are no longer autoloadable or referenced by project code, tests, examples or maintained documentation.
- [ ] Command mounting, duplicate-name precedence, suggestions, dispatch and concurrent-command behaviour remain unchanged and covered by tests.
