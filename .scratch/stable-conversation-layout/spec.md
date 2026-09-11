Status: ready-for-agent

# Keep the conversation controls anchored

## Problem Statement

When the History grows beyond the visible terminal, the Conversation TUI can
render more rows than the terminal owns. The terminal then scrolls physically
instead of the TUI maintaining a bounded viewport. As transient History content
appears or disappears—most visibly when the Working indicator is removed—the
Composer and Status line move vertically. A person can therefore lose the clear
visual anchor for where input belongs and may be unsure whether the Agent is
still working.

Command suggestions expose a related layout effect: because they currently
participate in the normal vertical layout, opening them changes how much History
is visible. Regardless of that deliberate first-stage behavior, they must not
push the Lower controls away from the bottom of the terminal.

The current centered vertical alignment also places the Composer prompt between
lines when the editable text spans multiple lines. On narrow terminals, the
Status line may wrap and unpredictably increase the height of the Lower
controls.

## Solution

The Conversation TUI will keep its Lower controls anchored to the bottom of the
terminal. Everything above them will live inside a bounded conversation
viewport that can use only the rows left after the Lower controls have been
laid out.

The viewport will contain the header, History and queued messages. When their
combined content is taller than the available region, the viewport will show a
window onto that content rather than allowing it to move the terminal itself.
When the person is following the latest content, the newest relevant rows stay
visible. After the person scrolls upward, the content they are reading stays
stable until they return to the bottom.

The Composer will continue to grow upward from one to five visible lines. Its
prompt will align with the first editable line. The Status line will remain one
row high and truncate when the terminal is too narrow.

## User Stories

1. As a person conversing with an Agent, I want the Composer to remain at the
   bottom of the terminal, so that I always know where to type.
2. As a person conversing with an Agent, I want the Status line to remain with
   the Composer, so that interaction guidance has a predictable location.
3. As a person waiting for an Agent, I want the Working indicator to remain
   visible throughout the Turn, so that pauses between output do not look like
   the Agent has stopped.
4. As a person watching a Turn finish, I want removal of the Working indicator
   not to move the Lower controls, so that the screen does not jump when work
   completes.
5. As a person reading a long answer, I want the History to remain within its
   allocated region, so that long content cannot push input controls off their
   expected rows.
6. As a person starting a short conversation, I want the header and short
   History to remain aligned at the top, so that empty space appears naturally
   between conversation content and the Lower controls.
7. As a person following live output, I want the viewport to follow the newest
   History content while I am at the bottom, so that I can read the Agent's
   response as it arrives.
8. As a person who has scrolled upward, I want the viewport to preserve the
   content I am reading when new output arrives, so that live activity does not
   interrupt my reading.
9. As a person who has scrolled upward, I want insertion or removal of transient
   History entries to preserve my reading position, so that animation and Turn
   lifecycle changes do not disorient me.
10. As a person returning to the latest History, I want subsequent output to be
    followed automatically again, so that normal live behavior resumes.
11. As a person queueing messages during a Turn, I want queued-message content
    to remain above the Lower controls, so that the Composer and Status line
    stay anchored even when the queue is large.
12. As a person using a small terminal, I want the upper conversation region to
    be clipped safely, so that the Lower controls retain priority over header,
    History and queued content.
13. As a person resizing the terminal, I want the Lower controls to settle at
    the new bottom immediately, so that the layout remains coherent at the new
    dimensions.
14. As a person opening Command suggestions, I want the Lower controls to remain
    at the bottom, so that command discovery does not move the typing area.
15. As a person closing Command suggestions, I want the Lower controls to remain
    on the same rows, so that dismissing temporary content causes no input-area
    jump.
16. As a person writing a multi-line message, I want the Composer to expand
    upward, so that its bottom anchor remains stable.
17. As a person writing a multi-line message, I want the prompt aligned with the
    first editable line, so that it clearly marks the beginning of my input.
18. As a person writing more than five visible lines, I want the Composer to
    retain its existing five-line maximum, so that it cannot consume the whole
    conversation viewport.
19. As a person using a narrow terminal, I want the Status line to occupy one
    row and truncate, so that help text cannot move the Composer unexpectedly.
20. As a Host Application author, I want the stable layout to belong to Neuron
    TUI, so that every application using the Conversation TUI receives the same
    behavior without application-specific rendering patches.
21. As a maintainer, I want the fix to avoid modifying Symfony TUI, so that
    dependency installation and upgrades do not remove or conflict with it.

## Implementation Decisions

- The root conversation layout will contain two vertical regions: one
  vertically expanding conversation viewport and one fixed Lower controls
  region.
- The conversation viewport will own the header, History and queued messages.
  It will never return more rendered rows than the layout allocates to it.
- Clipping the complete upper region, rather than only the History, guarantees
  the bottom anchor even when a small terminal or a large queued-message block
  leaves insufficient room for the header and queue themselves.
- The viewport will render a bottom window while following the latest content.
  Its scroll offset will be local to that viewport rather than delegated to the
  terminal-wide screen writer.
- Scrolling upward will preserve the visible reading position as History height
  changes. Scrolling back to the bottom will restore automatic following.
- The Working indicator remains a History entry. No permanent blank placeholder
  will be reserved for it.
- Command suggestions remain in the normal vertical layout for this change.
  They may reduce the visible conversation viewport, but they may not move the
  Lower controls away from the bottom.
- The Composer retains its existing minimum of one and maximum of five visible
  lines and grows upward as its text wraps or gains lines.
- The Composer prompt uses top alignment so that it sits beside the first line
  of editable text.
- The Status line is constrained to one rendered row and truncates content that
  exceeds the available width.
- The behavior is implemented entirely within Neuron TUI. Symfony TUI source
  and installed dependencies are not patched.
- No public Host Application API needs to change. Viewport ownership and scroll
  coordination remain internal presentation concerns.

## Testing Decisions

- Use one high-level behavioral seam: run the real Conversation TUI against a
  virtual terminal, drive it with public operations and terminal input, and
  reconstruct the physical screen produced by differential rendering.
- Assert on visible rows and content rather than widget classes, tree shape or
  clipping algorithms. A valid implementation may change internal composition
  without changing these tests.
- With a filled History, compare the physical Composer and Status line rows
  before, during and after the Working indicator. Both rows must retain their
  bottom anchor.
- Open and close Command suggestions after the History fills the viewport. The
  visible amount of History may change, but the Lower controls must remain on
  their anchored rows.
- Add enough queued messages to overflow the upper region, including on a small
  terminal, and verify that the Lower controls remain visible and anchored.
- Exercise terminal resizing and verify that the Lower controls move only to
  the newly calculated bottom rows.
- Exercise one through five Composer lines and verify upward growth, a fixed
  bottom edge and prompt alignment with the first line.
- Enter more than five lines and verify that the Composer does not exceed its
  visible maximum.
- Use a narrow terminal and verify that the Status line remains one row high and
  its text truncates rather than wraps.
- While following the latest content, append History and verify that the newest
  content remains visible.
- Scroll upward, then append and remove History entries, and verify that the
  previously visible reading position remains stable. Return to the bottom and
  verify that automatic following resumes.
- Existing full-TUI virtual-terminal tests provide the prior art for driving
  input and inspecting rendered output. Existing History scrolling tests
  provide prior art for follow-latest and preserved-reading-position behavior.
- The regression must fail against the current implementation for the reported
  screen jump and pass only when the terminal output remains bounded.

## Out of Scope

- Rendering Command suggestions as an overlay above the History.
- Changing which Commands appear in suggestions or how suggestion navigation,
  completion and execution work.
- Changing the text or interaction states represented by the Status line.
- Changing the Working indicator's animation, wording, duration or lifecycle.
- Increasing or removing the five-line Composer limit.
- Changing Picker layout or behavior.
- Modifying Symfony TUI's renderer, layout engine or screen writer.
- Adding configuration for Host Applications to choose alternative anchoring or
  clipping policies.

## Further Notes

The current failure originates at the boundary between vertical layout and
terminal rendering: an expanding child receives a row allocation but can emit
more content than that allocation. The resulting document exceeds terminal
height, causing physical terminal scrolling. A dependency-level slice masks
the symptom, but it changes global rendering behavior and does not establish
the Conversation TUI's intended layout ownership.

The bounded conversation viewport makes that ownership explicit and protects
the absolute bottom-anchor invariant even in more extreme cases than the
original Working-indicator reproduction. The later decision to make Command
suggestions an overlay can be evaluated independently.
