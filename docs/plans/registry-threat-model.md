# Registry threat model and security regressions

## Scope

Complete the registry portion of the application security backlog using the
standalone C++ service and disposable authenticated TLS rqlite fixtures. PHP
Discovery is separate local persistence. The registry has public lookup and
credential-owned identities, not application tenants.

Existing contracts cover HTTP framing, rate limits, upstream TLS failures,
redirect refusal, uncertain commits, partitions and quorum loss. Extend these
contracts where source review found missing evidence; preserve current protocol
semantics and deployment boundaries.

## Changes

- Add distinct-owner cross-API registration checks to the cluster contract:
  enrolled takeover fails, metadata and revisions remain unchanged, enrollment
  cannot move to another identity, and the owner can retry through another API.
- Add live HTTP regressions for replayed owner metadata, malformed JSON bytes and
  types, and secret absence in responses/logs. Replay advances revisions and can
  restore old metadata; this is a documented limitation, not replay prevention.
  Check the actual enrollment token, enrollment key and credential digest as well
  as the existing raw credential and database password markers.
- Write a registry threat model grounded in source and test evidence. Cover assets,
  trust boundaries, credential leakage, replay, cross-identity mutation, malformed
  HTTP/JSON, rate abuse, stored URL versus actual database egress, TLS and quorum
  downgrade. Record shared-key trust, missing credential rotation, global rate
  fairness, parser duplicate-key behavior and unqualified live HTTP fuzzing.
- Link the model from the registry README and application model. Keep regional
  deployment, S3 backup, resource-constrained soak and other existing findings open.
  Update ROADMAP.md with actual verification and the next actionable work.

No protocol, application API, schema, dependencies or production C++ changes are
planned. New failures require root-cause analysis and a reviewed scope adjustment.
Admission saturation and inbound legacy TLS probes are optional follow-up coverage;
this delivery must not imply they have been qualified.

## Verification and delivery

Independently review this plan before implementation using the documented manual
fallback for unavailable compound-engineering skills. Run syntax checks and the
full optimized registry release and sanitizer recipes on this checkout, serially,
using only temporary local processes and storage. Run the existing static gate;
record toolchain limitations rather than silently substituting a weaker check.
Existing quorum loss, redirect, TLS and rate-abuse contracts supply the other
focused security evidence. Container startup guard results remain prior evidence
unless rerun; no regional or cloud qualification is claimed.

Simplify and independently review the changes, apply justified findings, and run
prose-fix. Record counts, timings, observations and residual risks in ROADMAP.md.
Commit locally; publishing requires a separate request.
