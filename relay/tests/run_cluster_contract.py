#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Real 3/5-voter TLS partitions, quorum loss, acknowledged data and rejoin."""
import argparse
import http.client
import json
from pathlib import Path
import signal
import subprocess
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
                headers["X-Baander-Enrollment"] = issue_token(
                    self.cluster.enrollment_key,
                    body,
                    int(time.time() * 1000) + 60000,
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
        assert all(body["data"][key] == value for key, value in expected.items()), identifier
        assert "credential_digest" not in body["data"] and "apiKey" not in body["data"]


def wait_converged(cluster, indices, acknowledged_index):
    until = time.monotonic() + 30
    while time.monotonic() < until:
        try:
            states = [cluster.request(index, "/status")[1]["store"] for index in indices]
            leaders = {state["leader"]["node_id"] for state in states}
            if (
                len(leaders) == 1
                and "" not in leaders
                and all(int(state["db_applied_index"]) >= acknowledged_index for state in states)
            ):
                return int(next(iter(leaders))[5:])
        except (OSError, KeyError):
            pass
        time.sleep(0.05)
    raise AssertionError("Voters did not rejoin/apply acknowledged state within 30 seconds.")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--rqlited", required=True)
    parser.add_argument("--server", required=True)
    parser.add_argument("--nodes", type=int, choices=(3, 5), required=True)
    args = parser.parse_args()
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
            acknowledged = {}

            def write(api, identifier):
                value = registration(identifier)
                status, body, _ = api.request("POST", "/api/servers/register", value)
                assert status == 200, f"Expected committed registration: {identifier} ({status})"
                acknowledged[identifier] = {
                    "publicId": identifier,
                    "url": value["url"],
                    "name": value["name"],
                    "version": value["version"],
                    "revision": body["data"]["revision"],
                }

            for number in range(4):
                write(pinned, f"before-partition-{number}")
            verify(pinned, acknowledged)
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
            for _ in range(2):
                status, body, headers = minority_api.request(
                    "GET", "/api/servers/before-partition-0"
                )
                assert status == 503 and headers.get("Retry-After") == "1" and "url" not in body
            for number in range(4):
                write(majority_api, f"during-partition-{number}")
            acknowledged_index = int(
                cluster.request(new_leader, "/status")[1]["store"]["db_applied_index"]
            )
            cluster.network.heal()
            healed_leader = wait_converged(cluster, range(args.nodes), acknowledged_index)
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
            public_unavailability_errors = {
                "Authoritative registry unavailable.",
                "Authoritative database result unavailable.",
                "Authoritative registry result unavailable.",
                "Database request budget exhausted.",
                "Database request deadline exceeded.",
                "Registry query result unavailable.",
                "Registry result unavailable.",
            }
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
            for path in Path(directory).glob("*.log"):
                Path(f"/tmp/registry-cluster-{args.nodes}-{path.name}").write_text(path.read_text())
            raise
        finally:
            close_resources([*reversed(apis), observer, cluster])


if __name__ == "__main__":
    main()
