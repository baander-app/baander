---
name: forgejo
description: Manage Baander's Forgejo issues, pull requests, and CI runs when the user requests those repository operations.
---

# Forgejo repository operations

Use the repository's Forgejo API with `curl` and `jq`. The local `origin` is
`ssh://git@192.168.50.151:222/martin/baander.git`; the existing API base is
`http://192.168.50.151:3000/api/v1`. Use `FORGEJO_API_BASE` and `FORGEJO_REPO`
overrides when supplied, otherwise verify those defaults against `origin`.
Consume the already supplied `FORGEJO_TOKEN`. Report a missing token without
printing its value or sourcing interactive shell startup files.

Before an unfamiliar API operation, read the instance's `/swagger.v1.json`
(outside `/api/v1`) and `/api/v1/version` to check supported routes, parameters,
response schemas, pagination, and token scopes. A 404 may mean an unsupported
endpoint. Do not assume GitHub Actions schemas apply to Forgejo. Private HTTP
access is the existing local configuration; use the configured HTTPS endpoint
when available and do not send credentials to an unrelated host.

Use `Authorization: token <token>`, JSON content type for writes, and separate
HTTP status from response body. Avoid shell interpolation of titles, bodies,
branch names, and tokens: build payloads with `jq --arg` or a JSON encoder,
write bodies to a temporary file, and pass `--data-binary @file`. Encode query
parameters with `curl --get --data-urlencode`. Never enable shell tracing for
authenticated requests. Read repository guidance before drafting PR text.

## Issues and pull requests

Issue routes are `/repos/{owner}/{repo}/issues` and `/{number}`; comments use
`/{number}/comments`. PR routes are `/repos/{owner}/{repo}/pulls` and `/{number}`.
Confirm the schema for filters (including the issues-only filter), labels, and
pagination. Follow advertised next links or documented page/limit semantics;
do not present a single page as the complete result.

For issue creation, a supplied file can provide its first heading as title and
full text as body. PR head defaults to the current branch; verify the actual
base branch and that the head was pushed. Return the resulting `html_url`.
For comments, closing issues, or PR creation, prepare the requested exact body
and target before sending. Read-only status requests authorize reads. They do
not authorize unrelated comments, issue changes, merges, or publication.

## CI status and logs

Inspect supported actions routes in the instance schema. Common routes are
`/repos/{owner}/{repo}/actions/runs`, `/actions/runs/{id}/jobs`, and
`/actions/jobs/{id}/logs`. Use the actual returned fields for branch, source
commit, workflow, status, and conclusion rather than assuming `prettyref` or
`created` exists. Prefer server-side filters when supported; otherwise paginate
before filtering. Bind the report to a commit as well as a branch, especially
when reviewing a PR. Distinguish queued/running from completed success/failure.

Select the latest matching run using its documented timestamp/order, not the
first run from an arbitrary page. Retrieve failed-job logs with bounded output;
check content type for plain text, redirects, or archives. Retain a downloaded
log outside the repository and report the relevant failure and run/job URL.
Do not forward authorization headers to a different log-download host.

For 401/403, distinguish missing credentials, insufficient scopes, and denied
repository access where the response permits. For 422, report validation details.
For other failures, report HTTP status and relevant response text with secrets
redacted. Do not retry uncertain writes blindly; read the target to determine
whether the first attempt succeeded.
