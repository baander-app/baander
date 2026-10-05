#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Exercise the enrollment operator's file interface and independent HMAC vector."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest

ISSUER = Path(__file__).resolve().parents[1] / "tools" / "issue_enrollment.py"
spec = importlib.util.spec_from_file_location("issuer", ISSUER)
issuer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(issuer)
DIGEST = "ffe054fe7ae0cb6dc65c3af9b61d5209f439851db43d0ba5997337df154668eb"


class EnrollmentIssuer(unittest.TestCase):
    def test_known_signature_and_parameter_validation(self):
        self.assertEqual(
            issuer.issue_token(b"k" * 32, "security_server", DIGEST, 61000),
            "v1.61000.a5028ecb4a811b8abe92975c10ffe3ba1c9912d7c779c99e2f3c556ea3595977",
        )
        for key, identity, digest, expiry in (
            (b"k" * 31, "server", DIGEST, 1),
            (b"k" * 4097, "server", DIGEST, 1),
            (b"k" * 32, "server\nother", DIGEST, 1),
            (b"k" * 32, "server", DIGEST.upper(), 1),
            (b"k" * 32, "server", DIGEST, 10 ** 13),
        ):
            with self.assertRaises(ValueError):
                issuer.issue_token(key, identity, digest, expiry)

    def test_cli_reads_key_file_and_prints_only_capability(self):
        with tempfile.TemporaryDirectory() as temporary:
            key_file = Path(temporary) / "enrollment.key"
            key_file.write_bytes(b"k" * 32)
            command = [
                sys.executable,
                str(ISSUER),
                "--key-file", str(key_file),
                "--public-id", "security_server",
                "--credential-digest", DIGEST,
                "--ttl-seconds", "60",
            ]

            before = time.time_ns() // 1_000_000
            result = subprocess.run(command, capture_output=True, text=True, check=True)
            after = time.time_ns() // 1_000_000
            self.assertEqual(result.stderr, "")
            token = result.stdout.strip()
            expiry = int(token.split(".")[1])
            self.assertTrue(before + 60000 <= expiry <= after + 60000)
            self.assertEqual(
                result.stdout,
                issuer.issue_token(b"k" * 32, "security_server", DIGEST, expiry) + "\n",
            )

            default_before = time.time_ns() // 1_000_000
            default_result = subprocess.run(command[:-2], capture_output=True, text=True, check=True)
            default_after = time.time_ns() // 1_000_000
            default_expiry = int(default_result.stdout.strip().split(".")[1])
            self.assertTrue(default_before + 240000 <= default_expiry <= default_after + 240000)

            for invalid_ttl in ("0", "301"):
                rejected = subprocess.run(
                    command[:-1] + [invalid_ttl], capture_output=True, text=True
                )
                self.assertNotEqual(rejected.returncode, 0)
                self.assertEqual(rejected.stdout, "")

            key_file.write_bytes(b"secret" * 1000)
            rejected = subprocess.run(command, capture_output=True, text=True)
            self.assertNotEqual(rejected.returncode, 0)
            self.assertEqual(rejected.stdout, "")
            self.assertNotIn("secret", rejected.stderr)


if __name__ == "__main__":
    unittest.main()
