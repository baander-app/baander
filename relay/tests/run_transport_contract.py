#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Native TLS pool contract: disposable certificates, no production DNS or secrets."""
import argparse
import base64
import http.server
import json
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import time


def certificate(directory, name, hostname=None):
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
        server_key, server_certificate = certificate(directory, "server", "rqlite.baander.app")
        client_key, client_certificate = certificate(directory, "client")
        (directory / "password").write_text("disposable-test-secret\n")
        observations = []
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
                observations.append(
                    (
                        self.path,
                        self.headers.get("Authorization"),
                        bool(self.connection.getpeercert()),
                    )
                )
                delay = behavior["delay"]
                if behavior["delayOnce"]:
                    behavior["delay"] = 0
                    behavior["delayOnce"] = False
                time.sleep(delay)
                try:
                    self.send_response(behavior["status"])
                    self.send_header("Content-Length", str(len(behavior["body"])))
                    self.end_headers()
                    self.wfile.write(behavior["body"])
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

        def run(expected, count=1):
            config_path.write_text(json.dumps(config))
            completed = subprocess.run(
                [args.fixture, str(config_path), str(count)],
                check=True,
                capture_output=True,
                text=True,
                timeout=5,
            )
            assert sorted(completed.stdout.splitlines()) == sorted(expected), (
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
            print(
                "PASS: 8 native TLS scenarios (authentication, SNI, saturation/deadline, same-pool reconnect/keepalive, redirect, malformed response, hostname verification)."
            )
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)


if __name__ == "__main__":
    main()
