#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Native TLS pool contract: disposable certificates, no production DNS or secrets."""
import argparse
import base64
import http.server
import json
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import warnings


def authority(directory):
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
            "/CN=Baander disposable CA",
        ],
        check=True,
        capture_output=True,
    )


def certificate(directory, name, hostname=None, expired=False):
    key = directory / (name + ".key")
    request = directory / (name + ".csr")
    certificate_path = directory / (name + ".crt")
    subprocess.run(
        [
            "openssl",
            "req",
            "-new",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            str(key),
            "-out",
            str(request),
            "-subj",
            "/CN=" + (hostname or name),
        ],
        check=True,
        capture_output=True,
    )
    extension = directory / (name + ".ext")
    extension.write_text(
        "subjectAltName=DNS:" + hostname + "\nextendedKeyUsage=serverAuth\n"
        if hostname
        else "extendedKeyUsage=clientAuth\n"
    )
    validity = (
        ["-not_before", "20000101000000Z", "-not_after", "20010101000000Z"]
        if expired
        else []
    )

    subprocess.run(
        [
            "openssl",
            "x509",
            "-req",
            "-in",
            str(request),
            "-CA",
            str(directory / "ca.crt"),
            "-CAkey",
            str(directory / "ca.key"),
            "-CAcreateserial",
            "-out",
            str(certificate_path),
            "-days",
            "1",
            *validity,
            "-extfile",
            str(extension),
        ],
        check=True,
        capture_output=True,
    )
    return key, certificate_path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--fixture", required=True)
    args = parser.parse_args()
    with tempfile.TemporaryDirectory(prefix="baander-registry-tls-") as temporary:
        directory = Path(temporary)
        authority(directory)
        server_key, server_certificate = certificate(directory, "server", "rqlite.baander.app")
        client_key, client_certificate = certificate(directory, "client")
        (directory / "password").write_text("disposable-test-secret\n")
        observations = []
        arrivals = []
        behavior = {
            "status": 200,
            "delay": 0,
            "delayOnce": False,
            "body": b'{"results":[{"values":[[1]]}]}',
        }

        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *_):
                pass

            def do_POST(self):
                self.rfile.read(int(self.headers["Content-Length"]))
                selected = getattr(self.server, "behavior", behavior)
                arrivals.append((self.server.server_port, self.path))
                observations.append(
                    (
                        self.path,
                        self.headers.get("Authorization"),
                        bool(self.connection.getpeercert()),
                    )
                )
                delay = selected["delay"]
                if selected["delayOnce"]:
                    selected["delay"] = 0
                    selected["delayOnce"] = False
                time.sleep(delay)
                try:
                    self.send_response(selected["status"])
                    self.send_header("Content-Length", str(len(selected["body"])))
                    self.end_headers()
                    self.wfile.write(selected["body"])
                except (OSError, ssl.SSLError):
                    pass  # Expected cancellation closes the disposable client socket.

        class DisposableServer(http.server.ThreadingHTTPServer):
            def handle_error(self, _request, _client_address):
                pass  # Expected peer cancellation; assertions run in the parent test.

        server = DisposableServer(("127.0.0.1", 0), Handler)
        server.daemon_threads = True
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(server_certificate, server_key)
        tls.load_verify_locations(directory / "ca.crt")
        tls.verify_mode = ssl.CERT_REQUIRED
        names = []
        tls.set_servername_callback(lambda _socket, name, _context: names.append(name))
        server.socket = tls.wrap_socket(server.socket, server_side=True)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        config = {
            "url": f"https://rqlite.baander.app:{server.server_port}",
            "ca": str(directory / "ca.crt"),
            "certificate": str(client_certificate),
            "key": str(client_key),
            "passwordFile": str(directory / "password"),
            "deadlineMs": 250,
        }
        config_path = directory / "config.json"

        def run(expected, count=1, ordered=False):
            config_path.write_text(json.dumps(config))
            completed = subprocess.run(
                [args.fixture, str(config_path), str(count)],
                check=True,
                capture_output=True,
                text=True,
                timeout=5,
            )
            actual = completed.stdout.splitlines()
            diagnostic = completed.stdout + completed.stderr

            assert "disposable-test-secret" not in diagnostic, "Transport leaked its password."
            assert base64.b64encode(b"registry:disposable-test-secret").decode() not in diagnostic, (
                "Transport leaked its Authorization credential."
            )
            assert actual == expected if ordered else sorted(actual) == sorted(expected), (
                completed.stdout + completed.stderr
            )

        try:
            run(["200"])
            assert names == ["rqlite.baander.app"]
            assert observations[0] == (
                "/db/query?level=linearizable&associative",
                "Basic " + base64.b64encode(b"registry:disposable-test-secret").decode(),
                True,
            )
            behavior["delay"] = 0.6
            start = time.monotonic()
            run(["503", "503"], 2)  # One in flight, one immediately rejected by bounded pool.
            assert time.monotonic() - start < 1.5
            behavior["delay"] = 0.6
            behavior["delayOnce"] = True
            run(["503", "200"], -2)  # SAME pool recovers after cancellation.
            before = len(names)
            run(["200", "200"], -2)
            assert (
                len(names) == before + 1
            ), "Sequential requests did not reuse their TLS connection."
            healthy = DisposableServer(("127.0.0.1", 0), Handler)
            healthy.daemon_threads = True
            healthy.behavior = {
                "status": 200,
                "delay": 0,
                "delayOnce": False,
                "body": json.dumps(
                    {
                        "results": [
                            {
                                "types": {
                                    "public_id": "text",
                                    "url": "text",
                                    "name": "text",
                                    "version": "text",
                                    "updated_ms": "integer",
                                    "last_seen_ms": "integer",
                                    "revision": "integer",
                                },
                                "rows": [
                                    {
                                        "public_id": "rotation-fixture",
                                        "url": "https://rotation.baander.app",
                                        "name": "Rotation fixture",
                                        "version": "1.0.0",
                                        "updated_ms": 2000,
                                        "last_seen_ms": 2000,
                                        "revision": 1,
                                    }
                                ],
                            }
                        ]
                    }
                ).encode(),
            }
            healthy.socket = tls.wrap_socket(healthy.socket, server_side=True)
            healthy_thread = threading.Thread(target=healthy.serve_forever, daemon=True)
            healthy_thread.start()
            try:
                config["additionalUrls"] = [f"https://rqlite.baander.app:{healthy.server_port}"]
                config["rotateOnError"] = True
                # Real observed rqlite10.5.1 lease-loss response, deterministically replayed.
                behavior["body"] = b'{"results":[],"error":"leadership lost while committing log"}'
                before = len(arrivals)
                run(["503", "200"], -2, ordered=True)
                assert arrivals[before:] == [
                    (server.server_port, "/db/request?transaction&level=linearizable&associative"),
                    (healthy.server_port, "/db/query?level=linearizable&associative"),
                ], "Uncertain write was replayed or next lookup did not rotate."
            finally:
                healthy.shutdown()
                healthy.server_close()
                healthy_thread.join(timeout=2)
                config.pop("additionalUrls")
                config.pop("rotateOnError")
            behavior["status"] = 301
            run(["503"])  # Never follow redirects or downgrade consistency.
            behavior["status"] = 200
            behavior["body"] = b"not-json"
            run(["503"])
            behavior["body"] = b'{"results":[]}'
            config["url"] = f"https://wrong.registry.baander.app:{server.server_port}"
            before = len(observations)
            run(["503"])
            assert len(observations) == before, "Wrong TLS hostname reached authenticated HTTP."
            config["url"] = f"https://rqlite.baander.app:{server.server_port}"
            behavior["body"] = b'{"results":[{"values":[[1]]}]}'

            def rejected_tls(label, changes):
                previous = dict(config)
                before = len(observations)
                config.update(changes)
                try:
                    # Both sequential attempts must fail without exposing HTTP credentials.
                    run(["503", "503"], -2, ordered=True)
                    assert len(observations) == before, (
                        label + " reached authenticated upstream HTTP."
                    )
                finally:
                    config.clear()
                    config.update(previous)
                run(["200"])
                assert len(observations) == before + 1, label + " prevented healthy recovery."

            unrelated = directory / "unrelated-authority"
            unrelated.mkdir()
            authority(unrelated)
            rejected_tls("Untrusted server chain", {"ca": str(unrelated / "ca.crt")})

            rogue_key, rogue_certificate = certificate(unrelated, "untrusted-client")
            rejected_tls(
                "Untrusted client chain",
                {"certificate": str(rogue_certificate), "key": str(rogue_key)},
            )

            expired_key, expired_certificate = certificate(
                directory, "expired-server", "rqlite.baander.app", expired=True
            )
            expired_check = subprocess.run(
                [
                    "openssl",
                    "x509",
                    "-in",
                    str(expired_certificate),
                    "-checkend",
                    "0",
                    "-noout",
                ],
                capture_output=True,
            )
            assert expired_check.returncode == 1, "Expired certificate fixture is still valid."

            def server_rejection(label, key, cert, legacy=False):
                rejected_server = DisposableServer(("127.0.0.1", 0), Handler)
                rejected_server.daemon_threads = True
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                context.load_cert_chain(cert, key)
                context.load_verify_locations(directory / "ca.crt")
                context.verify_mode = ssl.CERT_REQUIRED

                if legacy:
                    with warnings.catch_warnings():
                        warnings.simplefilter("ignore", DeprecationWarning)
                        context.minimum_version = ssl.TLSVersion.TLSv1_1
                        context.maximum_version = ssl.TLSVersion.TLSv1_1
                    context.set_ciphers("DEFAULT:@SECLEVEL=0")

                rejected_server.socket = context.wrap_socket(
                    rejected_server.socket,
                    server_side=True,
                )
                rejected_thread = threading.Thread(
                    target=rejected_server.serve_forever,
                    daemon=True,
                )
                rejected_thread.start()

                try:
                    if legacy:
                        # A permissive control proves the endpoint actually supports TLS 1.1.
                        control = ssl.create_default_context(cafile=str(directory / "ca.crt"))
                        control.load_cert_chain(client_certificate, client_key)

                        with warnings.catch_warnings():
                            warnings.simplefilter("ignore", DeprecationWarning)
                            control.minimum_version = ssl.TLSVersion.TLSv1_1
                            control.maximum_version = ssl.TLSVersion.TLSv1_1
                        control.set_ciphers("DEFAULT:@SECLEVEL=0")

                        address = ("127.0.0.1", rejected_server.server_port)
                        with socket.create_connection(address, timeout=2) as raw:
                            with control.wrap_socket(
                                raw,
                                server_hostname="rqlite.baander.app",
                            ) as verified:
                                assert verified.version() == "TLSv1.1", (
                                    "Legacy TLS control failed."
                                )

                    rejected_tls(
                        label,
                        {"url": f"https://rqlite.baander.app:{rejected_server.server_port}"},
                    )
                finally:
                    rejected_server.shutdown()
                    rejected_server.server_close()
                    rejected_thread.join(timeout=2)

            server_rejection("Expired server certificate", expired_key, expired_certificate)
            server_rejection("TLS 1.1 protocol", server_key, server_certificate, legacy=True)

            print(
                "PASS: native TLS authentication/SNI, saturation/deadline, "
                "same-pool reconnect/keepalive, observed-error rotation without replay, "
                "redirect, malformed response, hostname verification, "
                "untrusted server/client chains, expired server certificate, "
                "TLS 1.1 rejection with positive control, "
                "credential redaction and healthy recovery."
            )
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)


if __name__ == "__main__":
    main()
