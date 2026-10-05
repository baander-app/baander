#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Issue a short-lived registry enrollment capability without exposing the key."""
import argparse
import hashlib
import hmac
from pathlib import Path
import re
import time


def issue_token(key: bytes, public_id: str, credential_digest: str, expiry_ms: int) -> str:
    if not 32 <= len(key) <= 4096:
        raise ValueError("Enrollment key must contain 32 to 4096 bytes.")
    if not re.fullmatch(r"[A-Za-z0-9_-]{1,128}", public_id):
        raise ValueError("Invalid public identity.")
    if not re.fullmatch(r"[0-9a-f]{64}", credential_digest):
        raise ValueError("Credential digest must be lowercase SHA-256 hex.")
    if not 0 < expiry_ms < 10 ** 13:
        raise ValueError("Invalid expiry.")
    message = f"baander-registry-enrollment-v1\n{public_id}\n{credential_digest}\n{expiry_ms}"

    signature = hmac.new(key, message.encode("ascii"), hashlib.sha256).hexdigest()
    return f"v1.{expiry_ms}.{signature}"


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--key-file", required=True, type=Path)
    parser.add_argument("--public-id", required=True)
    parser.add_argument("--credential-digest", required=True)
    parser.add_argument("--ttl-seconds", default=240, type=int)
    args = parser.parse_args()

    if not 1 <= args.ttl_seconds <= 300:
        parser.error("TTL must be between 1 and 300 seconds.")
    try:
        with args.key_file.open("rb") as source:
            key = source.read(4097)

        token = issue_token(
            key,
            args.public_id,
            args.credential_digest,
            time.time_ns() // 1_000_000 + args.ttl_seconds * 1000,
        )
    except (OSError, ValueError):
        parser.error("Invalid enrollment key file or enrollment parameters.")

    print(token)


if __name__ == "__main__":
    main()
