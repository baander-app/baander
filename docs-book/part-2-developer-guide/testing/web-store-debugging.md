# Web Store Isolation and Debugging

Run `yarn --cwd ui/web test:store-debug` for the disposable Chromium workflow at
`https://debug.baander.app:5187`, mapped to loopback with a temporary certificate.
It checks production selection/view-mode stores, render isolation, no-op updates,
and enabling, inspecting, exporting, and disabling the store debugger. The frontend
workflow runs this gate. Local prerequisites are Chromium and OpenSSL, as for the
audio graph tests; port 5187 must be free.

In a development build, enable the debug panel from Diagnostics, open Store Timeline
or Store Inspector, and select **Enable store tracing and reload**. Recording is
opt-in through `baander-store-debug` in local storage and is disabled in production
builds. Disabling also reloads, removing tracing wrappers instead of keeping a
conditional branch on every action. Enabling recording incurs snapshot/stack costs.

The timeline exports schema-version-2 JSON with ordered events, recorder instance
IDs, current captures and a history baseline. Each admitted store records a
registration capture after successful creation; construction-time intermediate
states are outside the recorded history. Duplicate display names have distinct
instance IDs. Retiring an instance with an explicit `unregisterStore(instanceId)`
call releases observation and removes that instance from later historical points
and the current Inspector. Retirement does not happen automatically when a component
unmounts.

Select a retained event to inspect **Historical store captures** for every recorded
instance present at that point. Reconstruction starts with the baseline and folds
in recorded captures through the selected event. It includes the full retained
history even when the timeline filter hides intervening events. It does not read
newer live state, change stores or run effects. Update before/after captures still
belong to the selected event.

Clearing or evicting the selected event removes selection. Collections over 50
instances use a keyboard-accessible virtualized scroll region; rows keep their
expanded or collapsed state while scrolling.

Evicted events advance the baseline to the start of the retained window. Clearing
the timeline starts a new baseline epoch from current recorded captures and does not
reuse instance or event IDs. At most 100 active instances are admitted. Omitted
registrations remain uninstrumented and increase the visible cumulative omission
count, which persists across clearing and retirement. A nonzero count marks
reconstruction as partial even when each displayed capture is complete. Retiring
an admitted instance frees a slot for a later registration.

Synchronous nested calls retain parent links. Async completions link to their
originating action; updates after an `await` retain their call stacks without
inferred parentage. Redacted, truncated and native values make captures incomplete.
Even complete captures are sanitized diagnostic values, not executable application
state. Reconstruction does not restore a running application or replay network or
media effects. Schema v1 has no compatibility conversion, and trace import is not
supported.

The timeline can verify one retained `catalog.selection` `select` or `clear`
action, including a successful no-op. It calculates the expected result with the
current pure transition shared by the live store, using detached captured data
and a trusted descriptor held by the recorder. Verification requires complete
starting-state, argument, update and terminal captures, matching transition
identity/version, and an unambiguous successful synchronous invocation. Nested,
foreign or unattributed updates and broken invocation boundaries make verification
unavailable. Results are match, mismatch or unavailable with a reason; a no-op
remains an explicit recorded action even when it emits no update.

Verification leaves live stores and recorder history unchanged and runs no live
effects. It supports neither async replay nor trace import. Schema-version-2
records without the optional invocation provenance remain inspectable but are
unavailable for verification. See the
[transition verifier](../../../ui/web/src/shared/stores/verify-transition.ts) for the
admission checks.

Unit tests verify creator/action identity when tracing is disabled, no-op subscriber
and persistence behavior, secret redaction, immutable snapshots, and event ordering.
They also cover instance registration and retirement, nested subscriber mutations,
baseline eviction and clear, admission omissions, and reconstruction without effects.
Player/session tests additionally cover clock isolation and sync under continuous
playback. Performance assertions use counts rather than timing-sensitive thresholds.
