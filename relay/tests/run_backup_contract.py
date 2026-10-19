#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Exercise the packaged backup tools against disposable authenticated TLS S3."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import sys
import time

from run_http_contract import port
from run_restore_contract import MIGRATION_QUERY, REGISTRY_QUERY, run_restore_scenario


def command(arguments, **kwargs):
    result = subprocess.run(arguments, capture_output=True, timeout=60, **kwargs)
    assert result.returncode == 0, (arguments, result.stdout[-4096:], result.stderr[-4096:])
    return result


def encrypted_snapshot(source, leader, root, coordinator):
    fixture = root / "encrypted-backup"
    fixture.mkdir(mode=0o700)
    objects = fixture / "objects"
    objects.mkdir()
    (objects / "registry-backups").mkdir()
    spool = fixture / "spool"
    spool.mkdir(mode=0o700)
    # Only the public recipient is written before recurring capture. Recovery
    # identities stay in the test process until the capture worker has exited.
    identity = command(["age-keygen"]).stdout
    recipient = command(["age-keygen", "-y"], input=identity).stdout
    recipients = fixture / "recipients.txt"
    recipients.write_bytes(recipient)
    tls_key, tls_cert = fixture / "s3.key", fixture / "s3.crt"
    command([
        "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
        "-keyout", str(tls_key), "-out", str(tls_cert), "-days", "1",
        "-subj", "/CN=backup-fixture.baander.app", "-addext", "subjectAltName=IP:127.0.0.1",
    ])
    s3_port = port()
    endpoint = f"https://127.0.0.1:{s3_port}"
    environment = dict(os.environ, RCLONE_CA_CERT=str(tls_cert), NO_PROXY="*", no_proxy="*")
    rclone_config = fixture / "rclone.conf"
    rclone_config.write_text(
        "[backup]\ntype = s3\nprovider = Other\nenv_auth = false\n"
        "access_key_id = disposable-access\nsecret_access_key = disposable-secret\n"
        f"region = us-east-1\nendpoint = {endpoint}\n"
        # rclone serve s3 does not support multipart upload; this is fixture only.
        "use_multipart_uploads = false\n"
    )
    rclone_config.chmod(0o600)
    curl_config = fixture / "curl.conf"
    curl_config.write_text(
        'user = "registry:disposable-cluster-secret"\n'
        f'cacert = "{source.directory / "ca.crt"}"\n'
        f'cert = "{source.client_cert}"\nkey = "{source.client_key}"\n'
        f'resolve = "node-{leader}.rqlite.baander.app:{source.http_ports[leader]}:127.0.0.1"\n'
        'noproxy = "*"\n'
    )
    curl_config.chmod(0o600)
    configuration = {
        "rqlite_url": f"https://node-{leader}.rqlite.baander.app:{source.http_ports[leader]}",
        "curl_config": str(curl_config), "recipients_file": str(recipients),
        "rclone_config": str(rclone_config), "destination": "backup:registry-backups/registry",
        "spool_dir": str(spool), "status_file": str(fixture / "status.json"),
        "attempt_timeout_seconds": 45, "max_ciphertext_bytes": 1024 * 1024,
    }
    config_path = fixture / "backup.json"
    config_path.write_text(json.dumps(configuration))
    successes = []
    with (fixture / "s3.log").open("wb") as log:
        service = subprocess.Popen([
            "rclone", "serve", "s3", str(objects), "--addr", f"127.0.0.1:{s3_port}",
            "--auth-key", "disposable-access,disposable-secret", "--cert", str(tls_cert),
            "--key", str(tls_key),
        ], stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 10
            while True:
                if service.poll() is not None:
                    raise AssertionError((fixture / "s3.log").read_text())
                try:
                    with socket.create_connection(("127.0.0.1", s3_port), timeout=0.2):
                        break
                except OSError:
                    assert time.monotonic() < deadline, "TLS S3 fixture did not start."
                    time.sleep(0.05)
            for _ in range(2):
                command([sys.executable, coordinator, "once", "--config", str(config_path)], env=environment)
                status = json.loads((fixture / "status.json").read_text())
                assert status["latest_attempt"]["outcome"] == "success", status
                command([sys.executable, coordinator, "health", "--config", str(config_path)], env=environment)
                successes.append(status["last_success"])
                assert list(spool.iterdir()) == [], "Completed capture retained spool artifacts."
            assert successes[0]["object_key"] != successes[1]["object_key"]
            listing = json.loads(command([
                "rclone", "--config", str(rclone_config), "lsjson", "backup:registry-backups/registry",
            ], env=environment).stdout)
            assert {entry["Name"] for entry in listing} == {
                Path(item["object_key"]).name for item in successes
            }, listing
            downloaded = []
            for index, success in enumerate(successes):
                target = fixture / f"download-{index}.sqlite.age"
                command([
                    "rclone", "--config", str(rclone_config), "copyto",
                    "backup:" + success["object_key"], str(target),
                ], env=environment)
                ciphertext = target.read_bytes()
                assert ciphertext.startswith(b"age-encryption.org/v1\n")
                assert b"SQLite format 3\x00" not in ciphertext
                assert hashlib.sha256(ciphertext).hexdigest() == success["sha256"]
                downloaded.append(target)
        finally:
            service.terminate()
            try:
                service.wait(timeout=5)
            except subprocess.TimeoutExpired:
                service.kill()
                service.wait(timeout=5)
    # Recovery is offline: the object service has stopped, and only now are
    # private identities materialized. No rejected decryption reaches /db/load.
    recovery = fixture / "recovery"
    recovery.mkdir(mode=0o700)
    private = recovery / "identity.txt"
    private.write_bytes(identity)
    private.chmod(0o600)
    wrong_private = recovery / "wrong-identity.txt"
    wrong_private.write_bytes(command(["age-keygen"]).stdout)
    wrong_private.chmod(0o600)
    modified = recovery / "modified.sqlite.age"
    damaged = bytearray(downloaded[0].read_bytes())
    damaged[-1] ^= 1
    modified.write_bytes(damaged)
    for label, key, ciphertext in (
        ("wrong-identity", wrong_private, downloaded[0]),
        ("modified", private, modified),
    ):
        rejected = recovery / f"{label}.sqlite"
        result = subprocess.run([
            "age", "--decrypt", "--identity", str(key), "--output", str(rejected), str(ciphertext),
        ], capture_output=True, timeout=10)
        assert result.returncode != 0, f"{label} ciphertext was accepted."
        # age may produce unauthenticated partial output before final failure;
        # failed output must never become input to the restore helper.
        rejected.unlink(missing_ok=True)
    recovered = []
    for index, ciphertext in enumerate(downloaded):
        target = recovery / f"verified-{index}.sqlite"
        command(["age", "--decrypt", "--identity", str(private), "--output", str(target), str(ciphertext)])
        with sqlite3.connect(f"file:{target}?mode=ro", uri=True) as database:
            assert database.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
            recovered.append((
                list(database.execute(REGISTRY_QUERY)), list(database.execute(MIGRATION_QUERY)),
            ))
    assert recovered[0] == recovered[1], "Historical captures changed the source rows."
    print("PASS: two encrypted TLS S3 objects, ciphertext hashes, offline recovery and rejection of wrong identity/tampering", flush=True)
    return (recovery / "verified-0.sqlite").read_bytes()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--rqlited", required=True)
    parser.add_argument("--server", required=True)
    parser.add_argument("--coordinator", default="/usr/local/bin/registry-backup")
    args = parser.parse_args()
    run_restore_scenario(
        args.rqlited, args.server,
        snapshot_provider=lambda source, leader, root: encrypted_snapshot(source, leader, root, args.coordinator),
    )


if __name__ == "__main__":
    main()
