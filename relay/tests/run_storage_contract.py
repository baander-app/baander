#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Prove real full-disk failure and exact recovery on a dedicated bounded tmpfs."""
import argparse
from contextlib import contextmanager
import errno
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import tempfile
import time

from cluster_support import Cluster
from run_cluster_contract import Api, close_resources, local_row

CAPACITY_BYTES = 128 * 1024 * 1024
MAX_REQUESTS = 2048
PRESSURE_SECONDS = 120


def usage(root):
    stat = os.statvfs(root)
    return {"capacity_bytes": stat.f_blocks * stat.f_frsize,
            "free_bytes": stat.f_bfree * stat.f_frsize,
            "available_bytes": stat.f_bavail * stat.f_frsize}


def validate_root(root):
    root = Path(root).absolute()
    if root.resolve(strict=True) != root or not root.is_dir():
        raise ValueError("Storage root must be an existing directory without symlinks.")
    mounts = []
    for line in Path("/proc/self/mountinfo").read_text().splitlines():
        fields = line.split()
        mountpoint = re.sub(r"\\([0-7]{3})", lambda match: chr(int(match[1], 8)), fields[4])
        if mountpoint == str(root):
            mounts.append((fields, fields.index("-")))
    if len(mounts) != 1 or mounts[0][0][mounts[0][1] + 1] != "tmpfs":
        raise ValueError("Storage root must be a dedicated tmpfs mount.")
    details = usage(root)
    if not 0 < details["capacity_bytes"] <= CAPACITY_BYTES:
        raise ValueError("Storage mount exceeds the 128 MiB capacity ceiling.")
    if root.stat().st_dev == root.parent.stat().st_dev:
        raise ValueError("Storage mount must have a distinct device from its parent.")
    if root.stat().st_dev == Path(tempfile.gettempdir()).stat().st_dev:
        raise ValueError("Fixture logs and artifacts must be outside the bounded filesystem.")
    if any(root.iterdir()):
        raise ValueError("Storage mount must initially be empty.")
    details.update({"mount": str(root), "device": root.stat().st_dev,
                    "mount_id": mounts[0][0][0]})
    return details


class BoundedStorage:
    def __init__(self, root):
        self.root = Path(root).absolute()
        self.evidence = validate_root(self.root)
        self.directory = Path(tempfile.mkdtemp(prefix="registry-", dir=self.root))
        self.filler = self.directory / "filler"

    def fill(self):
        written = 0
        with open(self.filler, "xb", buffering=0) as stream:
            try:
                while True:
                    written += stream.write(b"\0" * (1024 * 1024))
            except OSError as failure:
                if failure.errno != errno.ENOSPC:
                    raise
            stream.flush()
        return {"filler_errno": errno.ENOSPC, "filler_bytes": written, **usage(self.root)}

    def reclaim(self):
        self.filler.unlink()

    def close(self):
        shutil.rmtree(self.directory)


@contextmanager
def cleanup_scope(resources):
    try:
        yield
    except BaseException as failure:
        try:
            close_resources(resources)
        except BaseException as cleanup_failure:
            raise BaseExceptionGroup("Storage contract and cleanup failed", [failure, cleanup_failure]) from None
        raise
    else:
        close_resources(resources)


def pressure_registration(number):
    identifier = f"storage-pressure-{number:04d}"
    # Distinct, near-limit values occupy real database and Raft log bytes.
    path = hashlib.shake_256(identifier.encode()).hexdigest(1000)
    url = f"https://{identifier}.baander.app/"
    return {"publicId": identifier, "url": url + path[:2048-len(url)],
            "name": (identifier + "-" + path)[:128], "version": path[:64],
            "apiKey": secrets.token_hex(32)}


def acknowledge(ledger, submitted, body):
    data = body["data"]
    assert set(body) == {"data"} and set(data) == {
        "registered", "publicId", "revision", "updatedAtMs", "lastHeartbeatMs"}
    assert data["registered"] is True and data["publicId"] == submitted["publicId"]
    assert data["revision"] == 1 and type(data["updatedAtMs"]) is int
    assert data["updatedAtMs"] >= 0 and data["lastHeartbeatMs"] == data["updatedAtMs"]
    ledger[submitted["publicId"]] = {
        "public_id": submitted["publicId"],
        "credential_digest": hashlib.sha256(submitted["apiKey"].encode("ascii")).hexdigest(),
        **{key: submitted[key] for key in ("url", "name", "version")},
        "created_ms": data["updatedAtMs"], "updated_ms": data["updatedAtMs"],
        "last_seen_ms": data["lastHeartbeatMs"], "revision": 1}


def require_unavailable(status, body, headers):
    assert status == 503, f"Pressure returned unexpected HTTP {status}"
    errors = {"Authoritative registry unavailable.", "Authoritative database result unavailable.",
              "Authoritative registry result unavailable.", "Database request budget exhausted.",
              "Database request deadline exceeded.", "Registry query result unavailable.",
              "Registry result unavailable."}
    assert set(body) == {"error"} and body["error"] in errors, "Invalid unavailable envelope"
    assert {key.lower(): value for key, value in headers.items()}.get("retry-after") == "1"


def pressure(api, ledger, record):
    started = time.monotonic()
    for number in range(MAX_REQUESTS):
        if time.monotonic() - started >= PRESSURE_SECONDS:
            break
        submitted = pressure_registration(number)
        status, body, headers = api.request("POST", "/api/servers/register", submitted)
        elapsed = time.monotonic() - started
        if status == 200:
            acknowledge(ledger, submitted, body)
            record("acknowledged", {"pressure_requests": number + 1, "pressure_seconds": elapsed})
            assert elapsed <= PRESSURE_SECONDS, "Pressure time ceiling exceeded"
            continue
        require_unavailable(status, body, headers)
        record("unavailable", {"pressure_requests": number + 1, "pressure_seconds": elapsed,
                                "response": body, "status": status, "retry_after": "1"})
        assert elapsed <= PRESSURE_SECONDS, "Pressure time ceiling exceeded"
        return submitted
    raise AssertionError("Pressure request/time ceiling reached without a qualifying 503")


def require_storage_error(text):
    causes = set()
    for line in text.splitlines():
        if re.search(r"no space left on device|\bENOSPC\b", line, re.I):
            causes.add("voter storage: ENOSPC (no space left on device)")
        if re.search(r"database or disk is full|\bSQLITE_FULL\b", line, re.I):
            causes.add("voter SQLite: SQLITE_FULL (database or disk is full)")
    assert causes, "No voter storage diagnostic identifies ENOSPC or SQLite FULL"
    return sorted(causes)


def verify_rows(cluster, indices, ledger):
    for voter in indices:
        for identifier, expected in ledger.items():
            row = local_row(cluster, voter, identifier)
            fields = [key for key in expected if row is None or row.get(key) != expected[key]]
            assert not fields, f"voter {voter}, identity {identifier}: {', '.join(fields)}"


def verify_public(api, ledger):
    for identifier, row in ledger.items():
        status, body, _ = api.request("GET", "/api/servers/" + identifier)
        expected = {"publicId": identifier, **{key: row[key] for key in ("url", "name", "version")},
                    "updatedAtMs": row["updated_ms"], "lastHeartbeatMs": row["last_seen_ms"],
                    "revision": row["revision"]}
        assert status == 200 and body == {"data": expected}, f"Public ledger mismatch: {identifier}"


def wait_recovered(cluster, api, ledger, deadline):
    last = ""
    while time.monotonic() < deadline:
        try:
            assert api.process.poll() is None, "API exited during pressure/recovery"
            assert api.request("GET", "/ready")[0] == 200, "API not ready"
            verify_rows(cluster, range(3), ledger)
            verify_public(api, ledger)
            assert time.monotonic() <= deadline, "Recovery exceeded 30 seconds"
            return
        except (AssertionError, OSError, http.client.HTTPException) as failure:
            last = str(failure)
            time.sleep(0.05)
    raise AssertionError("Recovery failed within 30 seconds: " + last)


def run(args):
    # Preflight runs before even creating the ordinary temporary fixture.
    storage = BoundedStorage(args.storage_root)
    try:
        artifacts = Path(tempfile.mkdtemp(prefix="baander-registry-storage-evidence-"))
    except BaseException as failure:
        with cleanup_scope([storage]):
            raise failure
    evidence = {"storage": storage.evidence}
    ledger = {}
    resources = [storage]

    def record(phase, details):
        evidence.update({"phase": phase, **details})
        (artifacts / "evidence.json").write_text(json.dumps(evidence, indent=2))
        (artifacts / "ledger.json").write_text(json.dumps(ledger, indent=2))

    succeeded = False
    try:
        try:
            fixture = tempfile.TemporaryDirectory(prefix="baander-registry-storage-")
        except BaseException as failure:
            with cleanup_scope(resources):
                raise failure
        with fixture as directory:
            root = Path(directory)
            try:
                with cleanup_scope(resources):
                    record("validated", {})
                    cluster = Cluster(root, args.rqlited, 3, data_root=storage.directory)
                    resources.insert(0, cluster)
                    cluster.start()
                    api = Api(cluster, args.server, "storage-api", list(enumerate(cluster.http_ports)))
                    resources.insert(0, api)

                    for number in range(3):
                        submitted = dict(pressure_registration(number), publicId=f"storage-seed-{number}")
                        status, body, _ = api.request("POST", "/api/servers/register", submitted)
                        assert status == 200, f"Seed registration failed: HTTP {status}"
                        acknowledge(ledger, submitted, body)
                        record("seed", {})
                    wait_recovered(cluster, api, ledger, time.monotonic() + 30)
                    evidence["before_fill"] = usage(storage.root)
                    # Only diagnostics appended after filling can qualify the pressure fault.
                    offsets = {path: path.stat().st_size for path in root.glob("node-*.log")}
                    record("filled", storage.fill())
                    uncertain = pressure(api, ledger, record)
                    assert api.process.poll() is None and api.request("GET", "/health")[0] == 200
                    evidence["pressure_readiness"] = api.request("GET", "/ready")[0]
                    diagnostics = ""
                    for path, offset in offsets.items():
                        with open(path) as stream:
                            stream.seek(offset)
                            diagnostics += stream.read()
                    record("storage_failure", {"storage_errors": require_storage_error(diagnostics)})
                    recovery_started = time.monotonic()
                    storage.reclaim()
                    record("reclaimed", {"after_reclaim": usage(storage.root)})
                    cluster.stop_nodes()
                    for index in range(3):
                        cluster.start_node(index)
                    wait_recovered(cluster, api, ledger, recovery_started + 30)
                    record("recovered", {"recovery_seconds": time.monotonic() - recovery_started})

                    leader = cluster.wait_leader()
                    before = local_row(cluster, leader, uncertain["publicId"])
                    digest = hashlib.sha256(uncertain["apiKey"].encode("ascii")).hexdigest()
                    if before is not None:
                        assert before["credential_digest"] == digest, "Uncertain identity lost ownership"
                        assert all(before[key] == uncertain[key] for key in ("url", "name", "version"))
                        assert before["revision"] == 1
                        assert before["created_ms"] == before["updated_ms"] == before["last_seen_ms"]
                    status, body, _ = api.request("POST", "/api/servers/register", uncertain)
                    assert status == 200, f"Original credential retry failed: HTTP {status}"
                    if before is None:
                        acknowledge(ledger, uncertain, body)
                    else:
                        assert set(body) == {"data"} and set(body["data"]) == {
                            "registered", "publicId", "revision", "updatedAtMs", "lastHeartbeatMs"}
                        assert body["data"]["registered"] is True
                        assert body["data"]["publicId"] == uncertain["publicId"]
                        assert type(body["data"]["updatedAtMs"]) is int
                        assert body["data"]["revision"] == before["revision"] + 1
                        assert body["data"]["updatedAtMs"] >= before["updated_ms"]
                        assert body["data"]["lastHeartbeatMs"] == body["data"]["updatedAtMs"]
                        ledger[uncertain["publicId"]] = dict(before, revision=body["data"]["revision"],
                            updated_ms=body["data"]["updatedAtMs"], last_seen_ms=body["data"]["lastHeartbeatMs"])
                    record("retried", {"uncertain_was_present": before is not None})
                    fresh = dict(pressure_registration(0), publicId="storage-after-recovery")
                    status, body, _ = api.request("POST", "/api/servers/register", fresh)
                    assert status == 200, f"Fresh recovery registration failed: HTTP {status}"
                    acknowledge(ledger, fresh, body)
                    record("fresh_registered", {})
                    wait_recovered(cluster, api, ledger, time.monotonic() + 30)
                    record("verified", {"acknowledged_rows": len(ledger)})
            finally:
                for path in root.glob("*.log"):
                    shutil.copyfile(path, artifacts / path.name)
        succeeded = True
        print("PASS: real tmpfs exhaustion, storage-caused public 503, exact voter/public recovery "
              + json.dumps(evidence, sort_keys=True), flush=True)
    finally:
        if succeeded:
            shutil.rmtree(artifacts)
        else:
            print(f"Full-disk contract failure evidence: {artifacts}", flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--rqlited", required=True)
    parser.add_argument("--server", required=True)
    parser.add_argument("--storage-root", required=True)
    run(parser.parse_args())


if __name__ == "__main__":
    main()
