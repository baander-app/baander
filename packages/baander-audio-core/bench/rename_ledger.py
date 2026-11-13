#!/usr/bin/env python3
"""Rewrites a held-out ledger (work/heldout/runs.json) that the lossless
stage booked before the build digest's key was renamed: each run's
SHA-256 of the measured binary moves from OLD_KEY to NEW_KEY, the key the
stage reads.  The runs, their order and every other field stay as they
were, so the next run on the same recordings is still booked as a later
run and not as the first.

A ledger whose runs all use NEW_KEY is left alone; one that holds both
keys, or a run with neither, is refused.  Run it once in each checkout
whose work/ predates the rename, after moving work/ into this package:

    ./rename_ledger.py work/heldout/runs.json
"""
import argparse
import json
from pathlib import Path

OLD_KEY = "baac_sha256"
NEW_KEY = "bac_sha256"


def rename_ledger(path):
    """Rewrites the ledger at `path` in place and returns the number of
    runs it renamed: 0 when the ledger already uses NEW_KEY."""
    ledger = json.loads(Path(path).read_text())
    runs = ledger["runs"]
    old = sum(OLD_KEY in r for r in runs)
    if any(OLD_KEY not in r and NEW_KEY not in r for r in runs):
        raise SystemExit(f"{path} books a run without a build digest under {OLD_KEY} or "
                         f"{NEW_KEY}; the harness did not write it")
    if old and any(NEW_KEY in r for r in runs):
        raise SystemExit(f"{path} books runs under both {OLD_KEY} and {NEW_KEY}; "
                         "it was not written by one version of the harness")
    if not old:
        return 0
    ledger["runs"] = [{NEW_KEY if k == OLD_KEY else k: v for k, v in r.items()} for r in runs]
    Path(path).write_text(json.dumps(ledger, indent=1) + "\n")
    return old


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("ledger", type=Path, help="the ledger, usually work/heldout/runs.json")
    args = ap.parse_args()
    renamed = rename_ledger(args.ledger)
    print(f"{args.ledger}: renamed the digest of {renamed} run(s)" if renamed else
          f"{args.ledger}: already uses {NEW_KEY}")


if __name__ == "__main__":
    main()
