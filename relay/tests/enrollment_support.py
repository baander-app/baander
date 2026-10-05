# SPDX-License-Identifier: Apache-2.0
"""Independent enrollment capability oracle for disposable registry contracts."""

import hashlib
import hmac


def issue_token(key: bytes, registration: dict, expiry_ms: int) -> str:
    credential_digest = hashlib.sha256(registration["apiKey"].encode("ascii")).hexdigest()
    message = (
        "baander-registry-enrollment-v1\n"
        + registration["publicId"]
        + "\n"
        + credential_digest
        + "\n"
        + str(expiry_ms)
    ).encode("ascii")
    signature = hmac.new(key, message, hashlib.sha256).hexdigest()

    return f"v1.{expiry_ms}.{signature}"
