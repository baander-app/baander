#!/usr/bin/env python3
"""Publish HEAD's tree without rewriting local refs or including dirty work."""

import argparse
import json
import os
import re
import subprocess
import sys


class SyncError(Exception):
    pass


def git(*arguments):
    # Ref/object reads must not refresh the index. Respect configured hooks on
    # publication; disable background maintenance rather than any validation.
    environment = dict(os.environ, GIT_OPTIONAL_LOCKS="0")
    try:
        result = subprocess.run(
            ["git", "-c", "maintenance.auto=false", "-c", "gc.auto=0", *arguments],
            env=environment, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            check=False, timeout=60,
        )
    except subprocess.TimeoutExpired:
        if arguments[0] == "push":
            raise SyncError("Push timed out; remote outcome is uncertain. Inspect the destination before retrying") from None
        raise SyncError("Git inspection timed out after 60 seconds") from None
    if result.returncode:
        raise SyncError(result.stderr.strip() or "Git command failed")
    return result.stdout.strip()


def inspect(args):
    git("rev-parse", "--show-toplevel")
    if args.remote.startswith("-"):
        raise SyncError("Remote must be a configured name, not an option")
    if args.branch.startswith("-"):
        raise SyncError("Branch must not start with an option prefix")
    reference = "refs/heads/" + args.branch
    git("check-ref-format", reference)
    urls = git("remote", "get-url", "--push", "--all", args.remote).splitlines()
    if urls != [args.expected_url]:
        raise SyncError("Expected exactly one push URL matching --expected-url")
    source = git("rev-parse", "--verify", "HEAD^{commit}")
    tree = git("rev-parse", "--verify", source + "^{tree}")
    observed = git("ls-remote", "--refs", "--", args.expected_url, reference)
    rows = [row.split() for row in observed.splitlines() if row]
    if any(len(row) != 2 or row[1] != reference for row in rows) or len(rows) > 1:
        raise SyncError("Unexpected remote branch advertisement")
    sha = rows[0][0] if rows else "absent"
    if sha != "absent" and not re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", sha):
        raise SyncError("Invalid remote object ID")
    return {
        "source_commit": source, "tree": tree, "remote": args.remote,
        "push_url": args.expected_url, "reference": reference,
        "observed_sha": sha,
        "dirty_work_excluded": bool(git("status", "--porcelain")),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--remote", required=True)
    parser.add_argument("--expected-url", required=True)
    parser.add_argument("--branch", default="master")
    parser.add_argument("--expected-sha", help="Previewed SHA, or absent")
    parser.add_argument("--expected-source", help="Previewed source commit ID")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true")
    mode.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if args.expected_url.startswith("-") or not args.expected_url:
        parser.error("--expected-url must be a nonempty Git destination")
    if args.expected_sha and args.expected_sha != "absent" and not re.fullmatch(
        r"[0-9a-f]{40}|[0-9a-f]{64}", args.expected_sha
    ):
        parser.error("--expected-sha must be an object ID or absent")
    if args.apply and not args.expected_sha:
        parser.error("--apply requires the previewed --expected-sha")
    if args.expected_source and not re.fullmatch(r"[0-9a-f]{40}|[0-9a-f]{64}", args.expected_source):
        parser.error("--expected-source must be a full commit object ID")
    if args.apply and not args.expected_source:
        parser.error("--apply requires the previewed --expected-source")

    state = inspect(args)
    if args.expected_source and args.expected_source != state["source_commit"]:
        raise SyncError("Source HEAD changed from --expected-source; publication stopped")
    if args.expected_sha and args.expected_sha != state["observed_sha"]:
        raise SyncError("Remote branch changed from --expected-sha; publication stopped")
    if not args.apply:
        print(json.dumps(dict(state, mode="dry-run"), indent=2))
        return
    if git("rev-parse", "HEAD") != state["source_commit"]:
        raise SyncError("Source HEAD changed during inspection")
    commit = git("commit-tree", state["tree"], "-m", "initial commit")
    expected = "" if args.expected_sha == "absent" else args.expected_sha
    # Push the already validated URL rather than resolving a possibly changed
    # remote configuration. No local branch or remote-tracking ref is updated.
    git("push", "--porcelain",
        "--force-with-lease=" + state["reference"] + ":" + expected,
        "--", args.expected_url, commit + ":" + state["reference"])
    print(json.dumps(dict(state, mode="published", snapshot_commit=commit), indent=2))


if __name__ == "__main__":
    try:
        main()
    except (SyncError, OSError) as error:
        print("Snapshot sync failed: " + str(error), file=sys.stderr)
        sys.exit(1)
