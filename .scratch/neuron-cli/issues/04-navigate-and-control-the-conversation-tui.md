# 04 — Navigate and control the Conversation TUI

**What to build:** Complete the terminal interaction controls so that users can
navigate long conversations, exit predictably, correct local commands, and
receive clear feedback when the first version encounters an unsupported
terminal or human-in-the-loop workflow.

**Blocked by:** 02 — Hold a streamed Markdown conversation.

**Status:** ready-for-agent

- [ ] PageUp and PageDown browse long conversation content in bounded steps.
- [ ] New chunks follow the bottom automatically until the user scrolls upward;
      incoming activity then preserves the reading position until the user
      returns to the bottom.
- [ ] Ctrl+C and the exact `/exit` Slash command close the entire Conversation
      TUI and restore terminal state.
- [ ] Escape clears unsent composer text without closing the Conversation TUI.
- [ ] Unknown Slash commands remain local, are not sent to the Agent, display
      an English error, and remain available for correction.
- [ ] A human-in-the-loop workflow interruption displays an explicit unsupported
      message, does not attempt workflow resume, and restores the composer.
- [ ] Terminal startup failures propagate to the Host Application, while a
      non-interactive environment receives a clear failure instead of a
      plain-text fallback.
- [ ] Public-seam tests cover scrolling, live-follow behavior, keyboard
      controls, local Slash commands, unsupported workflow interruptions, and
      terminal restoration.
