# 01 — Open an Agent's existing History

**What to build:** Deliver an installable Neuron CLI library that a Host
Application can run with an already configured Agent. Opening the Conversation
TUI presents generic or host-supplied branding and safely renders the Agent's
existing History, giving developers a complete read-only first view of an
ongoing conversation.

**Blocked by:** None — can start immediately.

**Status:** ready-for-agent

- [ ] A Host Application can install the MIT-licensed Composer package on PHP
      8.4.1 or newer and run the public Neuron CLI module with a configured
      Neuron AI Agent.
- [ ] The public interface accepts optional title, subtitle, and Symfony
      terminal values while requiring no builder or configuration object.
- [ ] The default Conversation TUI uses generic English Neuron AI branding and
      preserves the playground's header, conversation, composer, status, and
      visual styling.
- [ ] Existing user and assistant text in History is visible when the TUI
      opens, with the initial view positioned at the newest content.
- [ ] System and reasoning content is hidden, while historical image, file,
      audio, and video content uses safe placeholders without exposing raw
      payloads.
- [ ] The Host Application remains responsible for Agent configuration and
      launch integration; the package provides no executable, Symfony Console
      command, provider setup, or credential handling.
- [ ] Deterministic tests drive the public module with a real Agent, fake
      provider, and virtual terminal without credentials or network access.
- [ ] English usage documentation shows the minimal and customized launch
      experience.
