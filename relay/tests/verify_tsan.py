#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Reject unavailable TSan runtimes as well as missed deliberate data races."""
import argparse
import os
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--probe", required=True)
    args = parser.parse_args()
    environment = os.environ.copy()
    environment["TSAN_OPTIONS"] = "halt_on_error=1:exitcode=66"
    result = subprocess.run(
        [args.probe], env=environment, capture_output=True, text=True, timeout=20
    )
    if result.returncode != 66 or "WARNING: ThreadSanitizer: data race" not in result.stderr:
        print(f"TSan positive control failed (exit {result.returncode}).", file=sys.stderr)
        print(result.stdout + result.stderr, file=sys.stderr)
        return 1
    print("TSan positive control detected the deliberate data race (exit 66).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
