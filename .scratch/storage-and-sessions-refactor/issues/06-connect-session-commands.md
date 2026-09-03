# 06: Connect Session Commands to runtime Sessions

**What to build:** Make the shipped Session Commands operate on the live Sessions supplied through Controls, so a Host Application can explicitly mount them without constructing a parallel Session dependency.

**Blocked by:** 05: Compose runtime-owned Sessions.

**Status:** ready-for-agent

- [ ] `SessionKit` can be constructed without a Session dependency and still explicitly mounts the clear and resume Commands.
- [ ] `ClearCommand` starts a new Session through Controls and installs its empty History without deleting or mutating the conversation it replaced.
- [ ] `ResumeCommand` lists the runtime Sessions, preserves the existing empty-list warning and Picker presentation, and installs the selected Session's History.
- [ ] Custom Command names remain supported without reintroducing a Session constructor dependency.
- [ ] The Commands use the same `Sessions` instance that the runtime started; mounting no kit still mounts no Commands automatically.
