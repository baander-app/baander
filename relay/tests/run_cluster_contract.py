#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Real 3/5-voter TLS partitions, quorum loss, acknowledged data and rejoin."""
import argparse
import hashlib
import http.client
import json
from pathlib import Path
import signal
import shutil
import subprocess
import sys
import tempfile
import time
from cluster_support import Cluster
from cluster_http_observer import Observer
from enrollment_support import issue_token
from run_http_contract import LocalHTTPS, port


class Api:
    def __init__(self, cluster, server, name, endpoints, database_connections=1):
        self.cluster = cluster
        self.port = port()
        directory = cluster.directory
        config = {
            "api": {
                "port": self.port,
                "certificate": str(cluster.api_cert),
                "key": str(cluster.api_key),
                "enrollmentKeyFile": str(cluster.enrollment_key_file),
                "deadlineMs": 5000,
            },
            "database": {
                "endpoints": [
                    {
                        "url": f"https://node-{index}.rqlite.baander.app:{number}",
                        "connectAddress": "127.0.0.1",
                    }
                    for index, number in endpoints
                ],
                "ca": str(directory / "ca.crt"),
                "certificate": str(cluster.client_cert),
                "key": str(cluster.client_key),
                "username": "registry",
                "passwordFile": str(directory / "password"),
                "connections": database_connections,
                "deadlineMs": 2000,
            },
        }
        path = directory / (name + ".json")
        path.write_text(json.dumps(config))
        self.log = open(directory / (name + ".log"), "w")
        self.process = None
        try:
            self.process = subprocess.Popen(
                [server, "--config", str(path)], stdout=self.log, stderr=subprocess.STDOUT
            )
            self.wait_ready()
        except BaseException as failure:
            try:
                self.close(check_exit=False)
            except BaseException as cleanup_failure:
                raise BaseExceptionGroup(
                    "API setup and cleanup failed", [failure, cleanup_failure]
                ) from None
            raise

    def connection(self):
        return LocalHTTPS(
            "api.registry.baander.app", self.port, context=self.cluster.api_tls, timeout=6
        )

    def request(self, method, target, body=None, enrollment=True):
        connection = self.connection()
        try:
            headers = {"Content-Type": "application/json"}
            if method == "POST" and target == "/api/servers/register" and enrollment:
                headers["X-Baander-Enrollment"] = (
                    enrollment
                    if isinstance(enrollment, str)
                    else issue_token(
                        self.cluster.enrollment_key,
                        body,
                        int(time.time() * 1000) + 60000,
                    )
                )

            connection.request(
                method,
                target,
                json.dumps(body) if body is not None else None,
                headers,
            )
            response = connection.getresponse()
            return response.status, json.loads(response.read()), dict(response.getheaders())
        finally:
            connection.close()

    def wait_ready(self):
        until = time.monotonic() + 20
        while time.monotonic() < until:
            if self.process.poll() is not None:
                raise AssertionError("Registry process exited before readiness.")
            try:
                if self.request("GET", "/ready")[0] == 200:
                    return
            except (OSError, http.client.HTTPException):
                pass
            time.sleep(0.05)
        raise AssertionError("Registry did not become ready.")

    def close(self, check_exit=True):
        try:
            if self.process is None:
                return
            if self.process.poll() is None:
                self.process.terminate()
            try:
                self.process.wait(timeout=7)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=3)
            if check_exit:
                assert self.process.returncode == 0, "Registry exited abnormally."
        finally:
            self.log.close()


def close_resources(resources):
    failures = []
    for resource in resources:
        if resource is None:
            continue
        try:
            resource.close()
        except BaseException as failure:
            failures.append(failure)
    if failures:
        raise BaseExceptionGroup("Cluster cleanup failed", failures)


def registration(identifier):
    return {
        "publicId": identifier,
        "url": f"https://{identifier}.baander.app",
        "name": identifier,
        "version": "1.0.0",
        "apiKey": "ac" * 32,
    }


def verify(api, acknowledged):
    for identifier, expected in acknowledged.items():
        status, body, _ = api.request("GET", "/api/servers/" + identifier)
        assert status == 200, f"Acknowledged identity unavailable: {identifier} ({status})"
        assert set(body) == {"data"} and body["data"] == expected, (
            f"Public snapshot mismatch for {identifier}"
        )


def verify_cross_api_ownership(first, second, cluster, voter):
    owner = dict(registration("cross-api-owner"), apiKey="34" * 32)
    target = "/api/servers/" + owner["publicId"]
    status, claimed, _ = first.request("POST", "/api/servers/register", owner)
    assert status == 200 and claimed["data"]["revision"] == 1

    def stored_row():
        status, result = cluster.request(
            voter,
            "/db/query?level=linearizable&associative",
            [["SELECT * FROM registries WHERE public_id=?", owner["publicId"]]],
        )
        assert status == 200
        rows = result["results"][0]["rows"]
        assert len(rows) == 1
        return rows[0]

    before = stored_row()
    status, lookup, _ = first.request("GET", target)
    assert status == 200
    before_public = lookup["data"]
    takeover = dict(
        owner,
        apiKey="56" * 32,
        url="https://cross-api-hostile.baander.app",
        name="Hostile replacement",
        version="99.0.0",
    )
    # The second claimant has valid enrollment for its own credential.
    assert second.request("POST", "/api/servers/register", takeover)[0] == 403
    assert stored_row() == before, "A cross-API takeover changed the stored owner row."
    for api in (first, second):
        status, lookup, _ = api.request("GET", target)
        assert status == 200 and lookup["data"] == before_public

    capability = issue_token(
        cluster.enrollment_key, owner, int(time.time() * 1000) + 60000
    )
    different_identity = dict(owner, publicId="cross-api-other-identity")
    assert second.request(
        "POST", "/api/servers/register", different_identity, enrollment=capability
    )[0] == 403
    for api in (first, second):
        assert api.request("GET", "/api/servers/" + different_identity["publicId"])[0] == 404
    assert stored_row() == before

    status, retried, _ = second.request(
        "POST", "/api/servers/register", owner, enrollment=False
    )
    assert status == 200 and retried["data"]["revision"] == 2
    for api in (first, second):
        status, lookup, _ = api.request("GET", target)
        assert status == 200 and lookup["data"]["revision"] == 2
        for field in ("publicId", "url", "name", "version"):
            assert lookup["data"][field] == owner[field]
    assert stored_row()["credential_digest"] == before["credential_digest"]
    print("PASS: distinct-owner cross-API claims, capability binding and owner retry", flush=True)


def local_row(cluster, voter, identifier):
    status, result = cluster.request(
        voter,
        "/db/query?level=none&associative",
        [["SELECT public_id, credential_digest, url, name, version, created_ms, "
          "updated_ms, last_seen_ms, revision FROM registries WHERE public_id=?", identifier]],
    )
    context = f"Local diagnostic for voter {voter}, identity {identifier}"
    assert status == 200, f"{context}: HTTP {status}"
    assert isinstance(result, dict) and "error" not in result, f"{context}: query failed"
    statements = result.get("results")
    assert isinstance(statements, list) and len(statements) == 1, f"{context}: invalid results"
    statement = statements[0]
    assert isinstance(statement, dict) and "error" not in statement, f"{context}: statement failed"
    expected_types = {
        "public_id": "text", "credential_digest": "text", "url": "text",
        "name": "text", "version": "text", "created_ms": "integer",
        "updated_ms": "integer", "last_seen_ms": "integer", "revision": "integer",
    }
    assert statement.get("types") == expected_types, f"{context}: invalid column types"
    rows = statement.get("rows", [])
    assert isinstance(rows, list) and len(rows) <= 1, f"{context}: invalid row count"
    if not rows:
        return None
    row = rows[0]
    assert isinstance(row, dict) and set(row) == set(expected_types), f"{context}: invalid row fields"
    return row


def wait_converged(cluster, indices, acknowledged_index, expected_rows=None):
    until = time.monotonic() + 30
    mismatches = []
    while time.monotonic() < until:
        try:
            states = [cluster.request(index, "/status")[1]["store"] for index in indices]
            leaders = {state["leader"]["node_id"] for state in states}
            applied = (
                len(leaders) == 1
                and "" not in leaders
                and all(int(state["db_applied_index"]) >= acknowledged_index for state in states)
            )
        except (OSError, KeyError):
            time.sleep(0.05)
            continue
        mismatches = []
        if applied:
            for voter in indices:
                for identifier, expected in (expected_rows or {}).items():
                    row = local_row(cluster, voter, identifier)
                    if row != expected:
                        fields = (
                            sorted(key for key in expected if row.get(key) != expected[key])
                            if row is not None else ["missing row"]
                        )
                        mismatches.append(f"voter {voter}, identity {identifier}: {', '.join(fields)}")
            if not mismatches:
                return int(next(iter(leaders))[5:])
        time.sleep(0.05)
    raise AssertionError(
        "Voters did not rejoin/apply acknowledged state within 30 seconds. " + "; ".join(mismatches)
    )


def export_diagnostics(directory, destination):
    if destination is None:
        return
    failed = sys.exception() is not None
    try:
        destination = Path(destination)
        destination.mkdir(parents=True, exist_ok=True)
        for path in Path(directory).glob("*.log"):
            shutil.copyfile(path, destination / path.name)
    except OSError as failure:
        # Keep the scenario's failure intact if retaining evidence also fails.
        print(f"Could not retain scenario diagnostics: {failure}", file=sys.stderr)
        if not failed:
            raise


def handle_termination(signum, _frame):
    raise SystemExit(128 + signum)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--rqlited", required=True)
    parser.add_argument("--server", required=True)
    parser.add_argument("--nodes", type=int, choices=(3, 5), required=True)
    parser.add_argument("--diagnostics", type=Path)
    args = parser.parse_args()
    signal.signal(signal.SIGTERM, handle_termination)
    with tempfile.TemporaryDirectory(prefix=f"baander-registry-{args.nodes}-voters-") as directory:
        cluster = Cluster(directory, args.rqlited, args.nodes)
        apis = []
        observer = None
        try:
            initial_leader = cluster.start()
            minority = {initial_leader}
            if args.nodes == 5:
                minority.add(next(index for index in range(args.nodes) if index != initial_leader))
            majority = set(range(args.nodes)) - minority
            observer = Observer(cluster, initial_leader)
            endpoints = (
                [(initial_leader, observer.port)]
                + [(index, cluster.http_ports[index]) for index in sorted(majority)]
                + [
                    (index, cluster.http_ports[index])
                    for index in sorted(minority)
                    if index != initial_leader
                ]
            )
            pinned = Api(cluster, args.server, "pinned", endpoints)
            apis.append(pinned)
            minority_api = Api(
                cluster,
                args.server,
                "minority",
                [(index, cluster.http_ports[index]) for index in sorted(minority)],
            )
            apis.append(minority_api)
            majority_api = Api(
                cluster,
                args.server,
                "majority",
                [(index, cluster.http_ports[index]) for index in sorted(majority)],
            )
            apis.append(majority_api)
            verify_cross_api_ownership(pinned, majority_api, cluster, initial_leader)
            acknowledged = {}

            def write(api, identifier, value=None, enrollment=True):
                value = registration(identifier) if value is None else value
                status, body, _ = api.request(
                    "POST", "/api/servers/register", value, enrollment=enrollment
                )
                assert status == 200, f"Expected committed registration: {identifier} ({status})"
                acknowledged[identifier] = {
                    "publicId": identifier,
                    "url": value["url"],
                    "name": value["name"],
                    "version": value["version"],
                    "revision": body["data"]["revision"],
                    "updatedAtMs": body["data"]["updatedAtMs"],
                    "lastHeartbeatMs": body["data"]["lastHeartbeatMs"],
                }

            for number in range(4):
                write(pinned, f"before-partition-{number}")
            verify(pinned, acknowledged)
            seed_identifier = "before-partition-0"
            new_identifier = "during-partition-0"
            baseline = local_row(cluster, initial_leader, seed_identifier)
            assert baseline is not None, "Seed row missing before partition."
            credential_digest = hashlib.sha256(registration(seed_identifier)["apiKey"].encode()).hexdigest()
            assert baseline["credential_digest"] == credential_digest, "Seed credential digest mismatch."
            baseline_index = int(
                cluster.request(initial_leader, "/status")[1]["store"]["db_applied_index"]
            )
            wait_converged(cluster, range(args.nodes), baseline_index, {seed_identifier: baseline})
            # Submit once through an existing TLS client during an actual Raft partition.
            # Its outcome is uncertain and is NEVER automatically replayed.
            connection = pinned.connection()
            connection.connect()
            observer.clear()
            partition_started = time.monotonic()
            cluster.network.partition(minority, blackhole=args.nodes == 5)
            uncertain_registration = registration("uncertain-partition")
            connection.request(
                "POST",
                "/api/servers/register",
                json.dumps(uncertain_registration),
                {
                    "Content-Type": "application/json",
                    "X-Baander-Enrollment": issue_token(
                        cluster.enrollment_key,
                        uncertain_registration,
                        int(time.time() * 1000) + 60000,
                    ),
                },
            )
            response = connection.getresponse()
            assert response.status == 503 and response.getheader("Retry-After") == "1"
            assert "url" not in json.loads(response.read())
            connection.close()
            observed = observer.snapshot()
            assert (
                len(observed) == 1 and observed[0]["error"] and observed[0]["status"] in (200, 503)
            ), observed
            print(
                f"PASS: real minority upstream HTTP{observed[0]['status']} error returns public503 without write replay",
                flush=True,
            )
            new_leader = cluster.wait_leader(majority, seconds=30)
            assert time.monotonic() - partition_started <= 30
            majority_api.wait_ready()
            verify(majority_api, acknowledged)
            # Read retries are independent requests; uncertain writes are never retried.
            until = time.monotonic() + 30
            while time.monotonic() < until:
                next_status, _, _ = pinned.request("GET", "/api/servers/before-partition-0")
                if next_status == 200:
                    break
                assert next_status == 503
            assert next_status == 200, "Configured healthy majority endpoints remained unavailable."
            print("PASS: configured endpoint recovery within30s", flush=True)
            for index in minority:
                status, body = cluster.request(
                    index,
                    "/db/query?level=linearizable&associative",
                    ["SELECT COUNT(*) AS total FROM registries"],
                )
                assert (
                    status != 200
                    or "error" in body
                    or any("error" in item for item in body.get("results", []))
                ), "Minority served an authoritative read."
            updated_seed = dict(
                registration(seed_identifier),
                url="https://updated-seed.baander.app",
                name="Updated partition seed",
                version="2.0.0",
            )
            write(majority_api, seed_identifier, updated_seed, enrollment=False)
            assert acknowledged[seed_identifier]["revision"] == baseline["revision"] + 1
            for number in range(4):
                write(majority_api, f"during-partition-{number}")
            expected_rows = {
                identifier: local_row(cluster, new_leader, identifier)
                for identifier in (seed_identifier, new_identifier)
            }
            for identifier, row in expected_rows.items():
                assert row is not None, f"Acknowledged local row missing: {identifier}"
                assert row["credential_digest"] == credential_digest, (
                    f"Acknowledged credential digest mismatch: {identifier}"
                )
                for public_field, stored_field in (
                    ("publicId", "public_id"), ("url", "url"), ("name", "name"),
                    ("version", "version"), ("revision", "revision"),
                    ("updatedAtMs", "updated_ms"), ("lastHeartbeatMs", "last_seen_ms"),
                ):
                    assert row[stored_field] == acknowledged[identifier][public_field], (
                        f"Acknowledged row mismatch for {identifier}: {stored_field}"
                    )
            current_seed = expected_rows[seed_identifier]
            assert current_seed["created_ms"] == baseline["created_ms"], "Owner update changed creation time."
            for field in ("updated_ms", "last_seen_ms"):
                assert current_seed[field] >= baseline[field], f"Owner update decreased {field}."
            acknowledged_index = int(
                cluster.request(new_leader, "/status")[1]["store"]["db_applied_index"]
            )
            wait_converged(cluster, sorted(majority), acknowledged_index, expected_rows)
            for index in sorted(minority):
                assert local_row(cluster, index, seed_identifier) == baseline, (
                    f"Minority voter {index} did not retain the obsolete seed row."
                )
                assert local_row(cluster, index, new_identifier) is None, (
                    f"Minority voter {index} unexpectedly has the new identity."
                )
            public_unavailability_errors = {
                "Authoritative registry unavailable.",
                "Authoritative database result unavailable.",
                "Authoritative registry result unavailable.",
                "Database request budget exhausted.",
                "Database request deadline exceeded.",
                "Registry query result unavailable.",
                "Registry result unavailable.",
            }
            # Both lookups must precede failed readiness, which clears initialization.
            for identifier in (seed_identifier, new_identifier):
                status, body, headers = minority_api.request("GET", "/api/servers/" + identifier)
                assert status == 503, f"Stale minority lookup for {identifier} returned {status}."
                assert headers.get("Retry-After") == "1", identifier
                assert set(body) == {"error"} and body["error"] in public_unavailability_errors, (
                    f"Stale minority lookup for {identifier} returned an unexpected public envelope."
                )
            status, body, headers = minority_api.request("GET", "/ready")
            assert status == 503 and headers.get("Retry-After") == "1"
            assert set(body) == {"error"} and body["error"] in public_unavailability_errors
            health_status, health_body, _ = minority_api.request("GET", "/health")
            assert health_status == 200 and health_body == {"data": {"alive": True}}
            verify(majority_api, acknowledged)
            verify(pinned, acknowledged)
            cluster.network.heal()
            healed_leader = wait_converged(
                cluster, range(args.nodes), acknowledged_index, expected_rows
            )
            minority_api.wait_ready()
            verify(minority_api, acknowledged)
            print(
                f"PASS: {len(majority)}–{len(minority)} partition, majority commits, leader recovery and voter rejoin",
                flush=True,
            )
            failures = {healed_leader}
            if args.nodes == 5:
                failures.add(next(index for index in range(args.nodes) if index != healed_leader))
            crash_started = time.monotonic()
            for index in failures:
                cluster.processes[index].kill()
                cluster.processes[index].wait(timeout=3)
            remaining = set(range(args.nodes)) - failures
            surviving_leader = cluster.wait_leader(remaining, seconds=30)
            assert time.monotonic() - crash_started <= 30
            survivor_api = Api(
                cluster,
                args.server,
                "survivors",
                [(index, cluster.http_ports[index]) for index in sorted(remaining)],
            )
            apis.append(survivor_api)
            verify(survivor_api, acknowledged)
            write(survivor_api, "after-voter-crash")
            final_index = int(
                cluster.request(surviving_leader, "/status")[1]["store"]["db_applied_index"]
            )
            for index in sorted(failures):
                cluster.start_node(index)
            recovered_leader = wait_converged(cluster, range(args.nodes), final_index)
            verify(survivor_api, acknowledged)
            print(
                f"PASS: no acknowledged loss after {len(failures)} abrupt voter failures, restart and catch-up",
                flush=True,
            )
            # Keep the API and its configured endpoints alive while stopping enough
            # persisted voters, including the leader, to make every quorum impossible.
            absent_identifier = "absent-before-quorum-loss"
            pinned.wait_ready()
            absent_status, absent_body, _ = pinned.request(
                "GET", "/api/servers/" + absent_identifier
            )
            assert absent_status == 404, (absent_status, absent_body)
            stopped = {recovered_leader}
            stopped.update(
                [index for index in range(args.nodes) if index != recovered_leader][
                    : args.nodes // 2
                ]
            )
            for index in sorted(stopped):
                cluster.processes[index].kill()
                cluster.processes[index].wait(timeout=3)
            assert len(stopped) == args.nodes // 2 + 1
            outage_registration = registration("uncertain-quorum-loss")
            outage_started = time.monotonic()
            for method, target, payload in (
                ("GET", "/api/servers/before-partition-0", None),
                ("GET", "/api/servers/" + absent_identifier, None),
                ("POST", "/api/servers/register", outage_registration),
                ("GET", "/ready", None),
            ):
                request_started = time.monotonic()
                status, body, headers = pinned.request(method, target, payload)
                elapsed = time.monotonic() - request_started
                assert elapsed < 6, f"No-quorum {method} {target} exceeded API deadline: {elapsed:.2f}s"
                assert status == 503, f"No-quorum {method} {target} returned {status}"
                assert headers.get("Retry-After") == "1", target
                # Fixed public errors exclude stored metadata, credentials, SQL,
                # upstream responses and the uncertain registration's request body.
                assert set(body) == {"error"} and body["error"] in public_unavailability_errors, (
                    target,
                    body,
                )
                assert pinned.process.poll() is None, "Registry exited during quorum loss."
                health_started = time.monotonic()
                health_status, health_body, _ = pinned.request("GET", "/health")
                assert time.monotonic() - health_started < 6
                assert health_status == 200 and health_body == {"data": {"alive": True}}
            assert time.monotonic() - outage_started < 30, "No-quorum probes exceeded 30 seconds."
            # A failed write has an uncertain outcome. Do not replay it or assert
            # its absence after recovery; only acknowledged identities are required.
            restart_started = time.monotonic()
            for index in sorted(stopped):
                cluster.start_node(index)
            wait_converged(cluster, range(args.nodes), final_index)
            pinned.wait_ready()
            assert time.monotonic() - restart_started <= 30, "Quorum recovery exceeded 30 seconds."
            verify(pinned, acknowledged)
            write(pinned, "after-quorum-restoration")
            verify(pinned, acknowledged)
            print(
                f"PASS: {len(stopped)}/{args.nodes} voters stopped, public 503 and live health, "
                "persisted quorum recovery within 30s without acknowledged loss",
                flush=True,
            )
        except Exception:
            for log in cluster.logs:
                log.flush()
            if args.diagnostics is None:
                for path in Path(directory).glob("*.log"):
                    Path(f"/tmp/registry-cluster-{args.nodes}-{path.name}").write_text(path.read_text())
            raise
        finally:
            try:
                close_resources([*reversed(apis), observer, cluster])
            finally:
                export_diagnostics(directory, args.diagnostics)


if __name__ == "__main__":
    main()
