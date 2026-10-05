#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Public TLS API against a disposable authenticated native rqlite instance."""
import argparse
import base64
import http.server
import threading
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
from pathlib import Path
import signal
import socket
import ssl
import subprocess
import tempfile
import time
from run_transport_contract import certificate
from enrollment_support import issue_token


def port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


class LocalHTTPS(http.client.HTTPSConnection):
    def connect(self):
        connection = socket.create_connection(
            ("127.0.0.1", self.port), self.timeout, source_address=self.source_address
        )
        self.sock = self._context.wrap_socket(connection, server_hostname=self.host)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--server", required=True)
    parser.add_argument("--rqlited", required=True)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="baander-registry-http-") as temporary:
        directory = Path(temporary)
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                str(directory / "ca.key"),
                "-out",
                str(directory / "ca.crt"),
                "-days",
                "1",
                "-addext",
                "basicConstraints=critical,CA:TRUE",
                "-addext",
                "keyUsage=critical,keyCertSign,cRLSign",
                "-subj",
                "/CN=Baander disposable API CA",
            ],
            check=True,
            capture_output=True,
        )
        db_key, db_certificate = certificate(directory, "database", "rqlite.baander.app")
        api_key, api_certificate = certificate(directory, "api", "api.registry.baander.app")
        client_key, client_certificate = certificate(directory, "client")
        (directory / "password").write_text("disposable-test-secret\n")
        (directory / "auth.json").write_text(
            json.dumps(
                [{"username": "registry", "password": "disposable-test-secret", "perms": ["all"]}]
            )
        )
        db_port, raft_port, api_port = port(), port(), port()
        enrollment_key = b"native-contract-enrollment-key-32-bytes"
        (directory / "enrollment.key").write_bytes(enrollment_key)
        config = {
            "database": {
                "endpoints": [
                    {"url": f"https://rqlite.baander.app:{db_port}", "connectAddress": "127.0.0.1"}
                ],
                "ca": str(directory / "ca.crt"),
                "certificate": str(client_certificate),
                "key": str(client_key),
                "username": "registry",
                "passwordFile": str(directory / "password"),
                "connections": 2,
                "deadlineMs": 500,
            },
            "api": {
                "enrollmentKeyFile": str(directory / "enrollment.key"),
                "address": "127.0.0.1",
                "port": api_port,
                "certificate": str(api_certificate),
                "key": str(api_key),
                "deadlineMs": 1000,
            },
        }
        client_tls = ssl.create_default_context(cafile=str(directory / "ca.crt"))
        client_tls.load_cert_chain(client_certificate, client_key)
        gate = {
            "delay": 0,
            "malformedReadiness": False,
            "seen": threading.Event(),
            "arrivals": 0,
            "waitFor": 1,
            "lock": threading.Lock(),
        }

        class ProxyHandler(http.server.BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers["Content-Length"]))
                if b"INSERT INTO registries" in body and gate["delay"]:
                    with gate["lock"]:
                        gate["arrivals"] += 1
                        if gate["arrivals"] >= gate["waitFor"]:
                            gate["seen"].set()
                    time.sleep(gate["delay"])
                connection = LocalHTTPS(
                    "rqlite.baander.app", db_port, context=client_tls, timeout=3
                )
                try:
                    connection.request(
                        "POST",
                        self.path,
                        body,
                        {
                            "Content-Type": "application/json",
                            "Authorization": self.headers.get("Authorization", ""),
                        },
                    )
                    response = connection.getresponse()
                    payload = response.read()
                    if gate["malformedReadiness"] and b"FROM registries WHERE 0" in body:
                        corrupted = json.loads(payload)
                        corrupted["results"][1] = {"types": {"public_id": "text"}}
                        payload = json.dumps(corrupted).encode()
                    self.send_response(response.status)
                    self.send_header("Content-Length", str(len(payload)))
                    self.end_headers()
                    self.wfile.write(payload)
                except (OSError, ssl.SSLError):
                    pass  # Expected when deadline/shutdown cancels the request.
                finally:
                    connection.close()

        class ProxyServer(http.server.ThreadingHTTPServer):
            daemon_threads = True

            def handle_error(self, _request, _client_address):
                pass

        proxy = ProxyServer(("127.0.0.1", 0), ProxyHandler)
        proxy_tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        proxy_tls.load_cert_chain(db_certificate, db_key)
        proxy_tls.load_verify_locations(directory / "ca.crt")
        proxy_tls.verify_mode = ssl.CERT_REQUIRED
        proxy.socket = proxy_tls.wrap_socket(proxy.socket, server_side=True)
        proxy_thread = threading.Thread(target=proxy.serve_forever, daemon=True)
        proxy_thread.start()
        config["database"]["endpoints"][0][
            "url"
        ] = f"https://rqlite.baander.app:{proxy.server_port}"
        (directory / "config.json").write_text(json.dumps(config))
        tls = ssl.create_default_context(cafile=str(directory / "ca.crt"))
        db_log = open(directory / "rqlite.log", "w")
        api_log = open(directory / "api.log", "w")
        db = subprocess.Popen(
            [
                args.rqlited,
                "-node-id",
                "registry-http-test",
                "-http-addr",
                f"127.0.0.1:{db_port}",
                "-raft-addr",
                f"127.0.0.1:{raft_port}",
                "-http-cert",
                str(db_certificate),
                "-http-key",
                str(db_key),
                "-http-ca-cert",
                str(directory / "ca.crt"),
                "-http-verify-client",
                "-auth",
                str(directory / "auth.json"),
                str(directory / "data"),
            ],
            stdout=db_log,
            stderr=subprocess.STDOUT,
        )
        limited = None
        graceful = None
        api = subprocess.Popen(
            [args.server, "--config", str(directory / "config.json")],
            stdout=api_log,
            stderr=subprocess.STDOUT,
        )

        def request(method, target, body=None, selected_port=api_port, enrollment=True):
            connection = LocalHTTPS(
                "api.registry.baander.app", selected_port, context=tls, timeout=3
            )
            try:
                headers = {"Content-Type": "application/json"}
                if method == "POST" and target == "/api/servers/register" and enrollment:
                    try:
                        registration_body = json.loads(body)
                        if isinstance(registration_body, dict) and "apiKey" in registration_body:
                            headers["X-Baander-Enrollment"] = issue_token(
                                enrollment_key, registration_body, int(time.time() * 1000) + 60000
                            )
                    except (ValueError, KeyError):
                        pass
                if isinstance(enrollment, str):
                    headers["X-Baander-Enrollment"] = enrollment
                connection.request(method, target, body, headers)
                response = connection.getresponse()
                return response.status, json.loads(response.read()), dict(response.getheaders())
            finally:
                connection.close()

        def raw_request(payload):
            with socket.create_connection(("127.0.0.1", api_port), timeout=3) as socket_connection:
                with tls.wrap_socket(
                    socket_connection,
                    server_hostname="api.registry.baander.app",
                ) as connection:
                    connection.settimeout(3)
                    connection.sendall(payload)
                    response = http.client.HTTPResponse(connection)
                    response.begin()
                    status = response.status
                    body = json.loads(response.read())
                    assert connection.recv(1) == b"", "Registry kept a raw request connection open."
                    return status, body

        try:
            status, readiness_body = None, None
            until = time.monotonic() + 15
            while True:
                try:
                    status, readiness_body, _ = request("GET", "/ready")
                    if status == 200:
                        break
                except (OSError, ssl.SSLError):
                    pass
                if time.monotonic() > until:
                    raise AssertionError(
                        f"Native TLS registry never became ready: {status} {readiness_body}"
                    )
                time.sleep(0.1)
            registration = {
                "publicId": "server-one",
                "url": "https://music.baander.app",
                "name": "Baander native API",
                "version": "1.0.0",
                "apiKey": "ab" * 32,
            }
            encoded = json.dumps(registration)
            owned_registration = dict(registration)
            timestamp = int(time.time() * 1000)
            invalid_enrollments = (
                False,
                "v1.bad",
                issue_token(enrollment_key, registration, timestamp - 1),
                issue_token(enrollment_key, registration, timestamp + 600000),
                issue_token(
                    enrollment_key, dict(registration, publicId="wrong-id"), timestamp + 60000
                ),
                issue_token(
                    enrollment_key, dict(registration, apiKey="cd" * 32), timestamp + 60000
                ),
            )
            for enrollment in invalid_enrollments:
                assert request(
                    "POST", "/api/servers/register", encoded, enrollment=enrollment
                )[0] == 403
                assert request("GET", "/api/servers/server-one")[0] == 404
            enrollment_token = issue_token(enrollment_key, registration, timestamp + 60000)
            duplicate_enrollment = (
                "POST /api/servers/register HTTP/1.1\r\n"
                "Host: api.registry.baander.app\r\n"
                "Content-Type: application/json\r\n"
                f"X-Baander-Enrollment: {enrollment_token}\r\n"
                f"X-Baander-Enrollment: {enrollment_token}\r\n"
                f"Content-Length: {len(encoded.encode())}\r\n\r\n"
                f"{encoded}"
            ).encode()
            assert raw_request(duplicate_enrollment)[0] == 403
            assert request("GET", "/api/servers/server-one")[0] == 404
            status, body, _ = request("POST", "/api/servers/register", encoded)
            assert status == 200 and body["data"]["revision"] == 1
            assert registration["apiKey"] not in json.dumps(body)
            status, body, _ = request("POST", "/api/servers/register", encoded, enrollment=False)
            assert status == 200 and body["data"]["revision"] == 2
            status, body, _ = request("GET", "/api/servers/server-one")
            assert status == 200 and body["data"]["url"] == registration["url"]
            assert registration["apiKey"] not in json.dumps(body)
            registration.update(
                apiKey="cd" * 32,
                url="https://attacker.baander.app",
                name="Hostile replacement",
                version="99.0.0",
            )
            before_hostile_update = body["data"]
            assert request("POST", "/api/servers/register", json.dumps(registration))[0] == 403
            assert request("GET", "/api/servers/server-one")[1]["data"] == before_hostile_update
            assert request("GET", "/api/servers/missing")[0] == 404
            assert request("POST", "/api/servers/register", "{")[0] == 400
            assert request("POST", "/api/servers/register", "{}")[0] == 422
            assert request("POST", "/api/servers/register", "[" * 9 + "0" + "]" * 9)[0] == 400
            assert request("POST", "/api/servers/register", " " * 8193)[0] == 413
            assert request("GET", "/db/query")[0] == 404
            assert request("GET", "/status")[0] == 404
            for target in ("/db/execute", "/db/request", "/db/load", "/db/backup", "/join"):
                assert request("POST", target, "[]")[0] == 404

            hostile_http = (
                b"POST /api/servers/register HTTP/1.1\r\n"
                b"Host: api.registry.baander.app\r\n"
                b"Content-Type: application/json\r\n"
            )
            malformed_requests = (
                hostile_http + b"Content-Length: 0\r\nContent-Length: 9\r\n\r\n",
                hostile_http + b"Content-Length: 0\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
                b"GET /health HTTP/1.1\r\nHost: " + b"a" * 8192 + b"\r\n\r\n",
            )
            for malformed_request in malformed_requests:
                status, error = raw_request(malformed_request)
                assert status == 400 and error == {"error": "Invalid or oversized HTTP request."}

            oversized_chunk = b" " * 8193
            status, error = raw_request(
                hostile_http
                + b"Transfer-Encoding: chunked\r\n\r\n"
                + f"{len(oversized_chunk):x}\r\n".encode()
                + oversized_chunk
                + b"\r\n0\r\n\r\n"
            )
            assert status == 413 and error == {"error": "Invalid or oversized HTTP request."}

            pipelined_claim = dict(owned_registration, publicId="pipelined-claim")
            pipelined_registration = json.dumps(pipelined_claim).encode()
            pipelined_enrollment = issue_token(
                enrollment_key, pipelined_claim, int(time.time() * 1000) + 60000
            )
            status, body = raw_request(
                b"GET /health HTTP/1.1\r\nHost: api.registry.baander.app\r\n\r\n"
                + hostile_http
                + f"X-Baander-Enrollment: {pipelined_enrollment}\r\n".encode()
                + f"Content-Length: {len(pipelined_registration)}\r\n\r\n".encode()
                + pipelined_registration
            )
            assert status == 200 and body == {"data": {"alive": True}}
            assert request("GET", "/api/servers/pipelined-claim")[0] == 404
            assert request("GET", "/api/servers/server-one")[1]["data"] == before_hostile_update
            assert request("GET", "/health")[0] == 200
            claim_a = dict(
                registration, publicId="concurrent-owner", name="Owner A", apiKey="ef" * 32
            )
            claim_b = dict(
                registration, publicId="concurrent-owner", name="Owner B", apiKey="12" * 32
            )
            with ThreadPoolExecutor(max_workers=2) as executor:
                claims = list(
                    executor.map(
                        lambda value: request("POST", "/api/servers/register", json.dumps(value)),
                        (claim_a, claim_b),
                    )
                )
            assert sorted(result[0] for result in claims) == [200, 403]
            winner = claim_a if claims[0][0] == 200 else claim_b
            assert (
                request("GET", "/api/servers/concurrent-owner")[1]["data"]["name"] == winner["name"]
            )
            assert (
                request("POST", "/api/servers/register", json.dumps(winner))[1]["data"]["revision"]
                == 2
            )
            # A TLS client holding a partial request cannot hold a session indefinitely.
            pending = tls.wrap_socket(
                socket.create_connection(("127.0.0.1", api_port)),
                server_hostname="api.registry.baander.app",
            )
            pending.sendall(b"GET /health HTTP/1.1\r\nHost: api.registry.baander.app\r\n")
            pending.settimeout(3)
            start = time.monotonic()
            assert pending.recv(1) == b""
            assert time.monotonic() - start < 2.5
            pending.close()
            assert request("GET", "/health")[0] == 200
            limited_port = port()
            limited_config = dict(
                config, api=dict(config["api"], port=limited_port, requestsPerSecond=1, burst=1)
            )
            (directory / "limited.json").write_text(json.dumps(limited_config))
            limited = subprocess.Popen(
                [args.server, "--config", str(directory / "limited.json")],
                stdout=api_log,
                stderr=subprocess.STDOUT,
            )
            until = time.monotonic() + 10
            while True:
                connection = LocalHTTPS(
                    "api.registry.baander.app", limited_port, context=tls, timeout=3
                )
                try:
                    connection.request("GET", "/ready")
                    response = connection.getresponse()
                    ready = response.status == 200
                    response.read()
                    if ready:
                        break
                except (OSError, ssl.SSLError):
                    pass
                finally:
                    connection.close()
                if time.monotonic() > until:
                    raise AssertionError("Rate-limited instance never became ready.")
                time.sleep(0.1)
            # One global bounded bucket, independent of actual local client source addresses.
            limited_statuses = []
            for number in range(2, 7):
                connection = LocalHTTPS(
                    "api.registry.baander.app",
                    limited_port,
                    context=tls,
                    timeout=3,
                    source_address=(f"127.0.0.{number}", 0),
                )
                connection.request("GET", "/api/servers/server-one")
                response = connection.getresponse()
                limited_statuses.append(response.status)
                if response.status == 429:
                    assert response.getheader("Retry-After") == "1"
                response.read()
                connection.close()
            assert limited_statuses.count(429) >= 4
            limited.terminate()
            assert limited.wait(timeout=3) == 0

            def database_request(statements, read=False):
                connection = LocalHTTPS(
                    "rqlite.baander.app", db_port, context=client_tls, timeout=3
                )
                target = (
                    "/db/query?level=linearizable&associative"
                    if read
                    else "/db/execute?transaction"
                )
                try:
                    connection.request(
                        "POST",
                        target,
                        json.dumps(statements),
                        {
                            "Content-Type": "application/json",
                            "Authorization": "Basic "
                            + base64.b64encode(b"registry:disposable-test-secret").decode(),
                        },
                    )
                    response = connection.getresponse()
                    result = json.loads(response.read())
                    assert response.status == 200 and all(
                        "error" not in item for item in result["results"]
                    )
                    return result
                finally:
                    connection.close()

            owned_row = [
                "SELECT public_id, credential_digest, url, name, version, created_ms, "
                "updated_ms, last_seen_ms, revision FROM registries WHERE public_id=?",
                "server-one",
            ]
            before_rejected_claim = database_request([owned_row], True)["results"][0]["rows"][0]
            assert request("POST", "/api/servers/register", json.dumps(registration))[0] == 403
            after_rejected_claim = database_request([owned_row], True)["results"][0]["rows"][0]
            assert after_rejected_claim == before_rejected_claim

            database_request([
                ["UPDATE registries SET last_seen_ms=? WHERE public_id=?", 1, "server-one"]
            ])
            assert request("GET", "/api/servers/server-one")[0] == 404
            assert request("POST", "/api/servers/register", json.dumps(registration))[0] == 403
            assert request("POST", "/api/servers/register", json.dumps(owned_registration))[0] == 200
            assert request("GET", "/api/servers/server-one")[1]["data"]["url"] == owned_registration["url"]

            gate["malformedReadiness"] = True
            assert request("GET", "/ready")[0] == 503
            assert request("GET", "/api/servers/server-one")[0] == 503
            gate["malformedReadiness"] = False
            assert request("GET", "/ready")[0] == 200
            original = database_request(["SELECT checksum FROM schema_migrations"], True)[
                "results"
            ][0]["rows"][0]["checksum"]
            database_request([["UPDATE schema_migrations SET checksum=?", "incompatible"]])
            assert request("GET", "/ready")[0] == 503
            assert request("GET", "/api/servers/server-one")[0] == 503
            assert request("POST", "/api/servers/register", encoded)[0] == 503
            database_request([["UPDATE schema_migrations SET checksum=?", original]])
            assert request("GET", "/ready")[0] == 200
            assert request("GET", "/api/servers/server-one")[0] == 200

            def launch_graceful(grace, identifier, deadline=1000, database_deadline=500):
                nonlocal graceful
                selected_port = port()
                selected_config = dict(
                    config,
                    api=dict(
                        config["api"],
                        port=selected_port,
                        shutdownGraceMs=grace,
                        deadlineMs=deadline,
                    ),
                )
                selected_config["database"] = dict(config["database"])
                if deadline is None:
                    selected_config["api"].pop("deadlineMs")
                if database_deadline is None:
                    selected_config["database"].pop("deadlineMs")
                else:
                    selected_config["database"]["deadlineMs"] = database_deadline
                path = directory / (identifier + ".json")
                path.write_text(json.dumps(selected_config))
                graceful = subprocess.Popen(
                    [args.server, "--config", str(path)], stdout=api_log, stderr=subprocess.STDOUT
                )
                until = time.monotonic() + 10
                while True:
                    try:
                        if request("GET", "/ready", selected_port=selected_port)[0] == 200:
                            return selected_port
                    except (OSError, ssl.SSLError):
                        pass
                    if time.monotonic() > until:
                        raise AssertionError("Drain instance never became ready.")
                    time.sleep(0.05)

            default_port = launch_graceful(
                3000, "default-budget", deadline=None, database_deadline=None
            )
            gate["delay"] = 2.5
            gate["seen"].clear()
            try:
                result = request(
                    "POST",
                    "/api/servers/register",
                    json.dumps(dict(registration, publicId="default-timeout-uncertain")),
                    default_port,
                )
            except (OSError, http.client.HTTPException) as error:
                assert gate["seen"].is_set(), "Complete request never reached the delayed database."
                raise AssertionError(
                    "Complete valid request under default deadlines disconnected instead of503."
                ) from error
            assert result[0] == 503 and result[2].get("Retry-After") == "1"
            gate["delay"] = 0
            graceful.terminate()
            assert graceful.wait(timeout=3) == 0
            gate["delay"] = 0.25
            gate["waitFor"] = 2
            gate["arrivals"] = 0
            gate["seen"].clear()
            with ThreadPoolExecutor(max_workers=2) as executor:
                writes = [
                    executor.submit(
                        request,
                        "POST",
                        "/api/servers/register",
                        json.dumps(dict(registration, publicId=f"capacity-{number}")),
                    )
                    for number in range(2)
                ]
                assert gate["seen"].wait(2)
                saturated = request("GET", "/api/servers/server-one")
                assert saturated[0] == 503 and saturated[2].get("Retry-After") == "1"
                assert all(write.result(timeout=3)[0] == 200 for write in writes)
            gate["delay"] = 0
            gate["waitFor"] = 1
            gate["arrivals"] = 0
            assert request("GET", "/api/servers/server-one")[0] == 200

            deadline_port = launch_graceful(500, "deadline", deadline=100)
            gate["delay"] = 0.3
            gate["seen"].clear()
            start = time.monotonic()
            result = request(
                "POST",
                "/api/servers/register",
                json.dumps(dict(registration, publicId="expired-unacknowledged")),
                deadline_port,
            )
            assert result[0] == 503 and result[2].get("Retry-After") == "1"
            assert gate["seen"].is_set(), "Deadline test never reached pending database work."
            assert time.monotonic() - start < 0.8
            gate["delay"] = 0
            assert request("GET", "/ready", selected_port=deadline_port)[0] == 200
            graceful.terminate()
            assert graceful.wait(timeout=2) == 0
            graceful_port = launch_graceful(1000, "drain")
            gate["delay"] = 0.25
            gate["seen"].clear()
            draining_registration = dict(registration, publicId="drained-commit")
            with ThreadPoolExecutor(max_workers=1) as executor:
                pending_commit = executor.submit(
                    request,
                    "POST",
                    "/api/servers/register",
                    json.dumps(draining_registration),
                    graceful_port,
                )
                assert gate["seen"].wait(2)
                graceful.send_signal(signal.SIGTERM)
                assert pending_commit.result(timeout=3)[0] == 200
                assert graceful.wait(timeout=3) == 0
            gate["delay"] = 0
            assert request("GET", "/api/servers/drained-commit")[0] == 200

            forced_port = launch_graceful(50, "forced-drain")
            gate["delay"] = 0.8
            gate["seen"].clear()
            with ThreadPoolExecutor(max_workers=1) as executor:
                pending_commit = executor.submit(
                    request,
                    "POST",
                    "/api/servers/register",
                    json.dumps(dict(registration, publicId="forced-unacknowledged")),
                    forced_port,
                )
                assert gate["seen"].wait(2)
                start = time.monotonic()
                graceful.send_signal(signal.SIGTERM)
                assert graceful.wait(timeout=2) == 0
                assert time.monotonic() - start < 1
                try:
                    result = pending_commit.result(timeout=2)
                    assert result[0] != 200, "Cutoff incorrectly acknowledged an unfinished commit."
                except (OSError, http.client.HTTPException):
                    pass
            gate["delay"] = 0
            api_log.flush()
            assert "disposable-test-secret" not in (directory / "api.log").read_text()
            assert ("ab" * 32) not in (directory / "api.log").read_text()
            db.terminate()
            db.wait(timeout=5)
            status, body, headers = request("GET", "/api/servers/server-one")
            assert status == 503 and headers.get("Retry-After") == "1"
            assert "url" not in body
            assert request("GET", "/ready")[0] == 503
            assert request("GET", "/health")[0] == 200
            pending = tls.wrap_socket(
                socket.create_connection(("127.0.0.1", api_port)),
                server_hostname="api.registry.baander.app",
            )
            pending.sendall(b"GET /health HTTP/1.1\r\n")
            start = time.monotonic()
            api.send_signal(signal.SIGTERM)
            assert api.wait(timeout=3) == 0
            assert time.monotonic() - start < 3
            pending.settimeout(1)
            assert pending.recv(1) == b""
            pending.close()
            print(
                "PASS: native TLS API enrollment capability/anonymous denial/owner heartbeat/concurrent ownership, depth/body bounds, rate/pool limits, schema recovery, outage/readiness/liveness, overall deadlines, graceful commit drain and forced shutdown."
            )
        except Exception:
            db_log.flush()
            api_log.flush()
            Path("/tmp/registry-http-rqlite-failure.log").write_text(
                (directory / "rqlite.log").read_text()
            )
            Path("/tmp/registry-http-api-failure.log").write_text(
                (directory / "api.log").read_text()
            )
            raise
        finally:
            for process in (api, db, limited, graceful):
                if process is not None and process.poll() is None:
                    process.terminate()
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait()
            proxy.shutdown()
            proxy.server_close()
            proxy_thread.join(timeout=2)
            db_log.close()
            api_log.close()


if __name__ == "__main__":
    main()
