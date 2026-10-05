#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Restore a bounded local binary backup into a fresh authenticated voter cluster."""
import argparse
import base64
import hashlib
import http.client
import json
from pathlib import Path
import sqlite3
import tempfile
import time
from cluster_support import Cluster
from run_cluster_contract import Api, close_resources, registration
from run_http_contract import LocalHTTPS


# Version 1's recorded schema checksum is part of the registry storage contract.
SCHEMA_CHECKSUM = "7dd3d552cbd6e3053846485d17b6dddf6cfbae2ef41136a1fa6fbaf06a38fcfd"
REGISTRY_QUERY = "SELECT * FROM registries ORDER BY public_id"
MIGRATION_QUERY = "SELECT version, checksum FROM schema_migrations ORDER BY version"
MAX_BACKUP_BYTES = 1024 * 1024


def binary_request(cluster, leader, method, target, payload=None):
    connection = LocalHTTPS(
        f"node-{leader}.rqlite.baander.app", cluster.http_ports[leader],
        context=cluster.tls, timeout=10,
    )
    try:
        connection.request(method, target, payload, {
            "Authorization": "Basic " + base64.b64encode(
                b"registry:disposable-cluster-secret"
            ).decode(),
            "Content-Type": "application/octet-stream",
        })
        response = connection.getresponse()
        body = response.read(MAX_BACKUP_BYTES + 1)
        assert len(body) <= MAX_BACKUP_BYTES, "Fixture response exceeded its size bound."
        return response.status, body, response.getheader("Content-Type")
    finally:
        connection.close()


def query_rows(cluster, index, query, level="linearizable"):
    status, body = cluster.request(index, f"/db/query?level={level}&associative", [query])
    assert status == 200 and "error" not in body, body
    result = body["results"][0]
    assert "error" not in result, result
    return result.get("rows", [])


def wait_rows(cluster, expected, migrations):
    until = time.monotonic() + 30
    while time.monotonic() < until:
        try:
            # level=none is intentional: inspect each voter's own database rather
            # than forwarding authoritative reads to the leader.
            if all(
                query_rows(cluster, index, REGISTRY_QUERY, "none") == expected
                and query_rows(cluster, index, MIGRATION_QUERY, "none") == migrations
                for index in range(cluster.count)
            ):
                return
        except (OSError, http.client.HTTPException, AssertionError):
            pass
        time.sleep(0.05)
    raise AssertionError("Restored rows did not converge on every voter within 30 seconds.")


def lookup(api, identifier):
    status, body, _ = api.request("GET", "/api/servers/" + identifier)
    assert status == 200, (identifier, status, body)
    assert "credential_digest" not in body["data"] and "apiKey" not in body["data"]
    return body["data"]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--rqlited", required=True)
    parser.add_argument("--server", required=True)
    parser.add_argument("--nodes", type=int, choices=(3, 5), default=3)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="baander-registry-restore-") as directory:
        root = Path(directory)
        source_dir, restored_dir = root / "source", root / "restored"
        source_dir.mkdir()
        restored_dir.mkdir()
        source = restored = source_api = restored_api = None
        try:
            source = Cluster(source_dir, args.rqlited, args.nodes)
            leader = source.start()
            source_api = Api(source, args.server, "source-api", list(enumerate(source.http_ports)))
            values = [registration(f"backup-owner-{number}") for number in range(3)]
            for value in values:
                status, body, _ = source_api.request("POST", "/api/servers/register", value)
                assert status == 200 and body["data"]["revision"] == 1
            values[0].update(name="Updated before backup", version="2.0.0")
            status, body, _ = source_api.request("POST", "/api/servers/register", values[0])
            assert status == 200 and body["data"]["revision"] == 2
            public = {value["publicId"]: lookup(source_api, value["publicId"]) for value in values}
            rows = query_rows(source, leader, REGISTRY_QUERY)
            migrations = [{"version": 1, "checksum": SCHEMA_CHECKSUM}]
            assert query_rows(source, leader, MIGRATION_QUERY) == migrations
            for row, value in zip(rows, values):
                assert row["public_id"] == value["publicId"]
                assert row["credential_digest"] == hashlib.sha256(
                    value["apiKey"].encode()
                ).hexdigest()
                assert row["revision"] == public[value["publicId"]]["revision"]
            status, backup, content_type = binary_request(
                source, leader, "GET", "/db/backup?fmt=binary"
            )
            assert status == 200 and content_type == "application/octet-stream"
            assert backup.startswith(b"SQLite format 3\x00"), "Backup is not a SQLite database."
            backup_path = root / "backup.sqlite"
            backup_path.write_bytes(backup)
            with sqlite3.connect(f"file:{backup_path}?mode=ro", uri=True) as database:
                database.row_factory = sqlite3.Row
                assert database.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
                assert [dict(row) for row in database.execute(REGISTRY_QUERY)] == rows
                assert [dict(row) for row in database.execute(MIGRATION_QUERY)] == migrations
            status, body, _ = source_api.request("POST", "/api/servers/register", values[0])
            assert status == 200 and body["data"]["revision"] == 3
            assert source_api.request(
                "POST", "/api/servers/register", registration("after-backup-sentinel")
            )[0] == 200
            assert source_api.request("GET", "/api/servers/after-backup-sentinel")[0] == 200
            close_resources([source_api, source])
            source_api = source = None

            # Fresh paths and a fresh CA prevent accidental reuse of source state.
            assert not any(restored_dir.iterdir())
            restored = Cluster(restored_dir, args.rqlited, args.nodes)
            assert (source_dir / "ca.crt").read_bytes() != (restored_dir / "ca.crt").read_bytes()
            leader = restored.start()
            assert query_rows(
                restored, leader, "SELECT name FROM sqlite_master WHERE type='table'"
            ) == []
            status, payload, _ = binary_request(restored, leader, "POST", "/db/load", backup)
            response = json.loads(payload)
            assert status == 200 and "error" not in response, response
            assert not any("error" in result for result in response.get("results", [])), response
            wait_rows(restored, rows, migrations)
            restored_api = Api(
                restored, args.server, "restored-api", list(enumerate(restored.http_ports))
            )
            assert restored_api.request("GET", "/ready")[0] == 200
            for identifier, expected in public.items():
                assert lookup(restored_api, identifier) == expected
            assert restored_api.request("GET", "/api/servers/after-backup-sentinel")[0] == 404
            wrong_owner = dict(values[0], apiKey="bd" * 32)
            assert restored_api.request("POST", "/api/servers/register", wrong_owner)[0] == 403
            assert lookup(restored_api, values[0]["publicId"]) == public[values[0]["publicId"]]
            assert query_rows(restored, leader, REGISTRY_QUERY) == rows
            status, body, _ = restored_api.request("POST", "/api/servers/register", values[0])
            assert status == 200 and body["data"]["revision"] == 3
            heartbeat = lookup(restored_api, values[0]["publicId"])
            assert heartbeat["revision"] == 3
            assert heartbeat["lastHeartbeatMs"] >= public[values[0]["publicId"]]["lastHeartbeatMs"]
            updated_rows = query_rows(restored, leader, REGISTRY_QUERY)
            wait_rows(restored, updated_rows, migrations)
            print(
                f"PASS: local binary backup integrity, fresh {args.nodes}-voter restore, "
                "snapshot boundary, ownership and voter convergence",
                flush=True,
            )
        finally:
            close_resources([restored_api, restored, source_api, source])


if __name__ == "__main__":
    main()
