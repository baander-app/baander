# SPDX-License-Identifier: Apache-2.0
"""Persistent fixture HTTP observer forwarding to a real authenticated TLS node."""
import http.server
import json
import ssl
import threading
from run_http_contract import LocalHTTPS


class Observer:
    def __init__(self, cluster, node):
        observations = self.observations = []
        lock = self.lock = threading.Lock()

        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def setup(self):
                super().setup()
                self.upstream = LocalHTTPS(
                    f"node-{node}.rqlite.baander.app",
                    cluster.http_ports[node],
                    context=cluster.tls,
                    timeout=4,
                )

            def finish(self):
                self.upstream.close()
                super().finish()

            def log_message(self, *_):
                pass

            def do_POST(self):
                body = self.rfile.read(int(self.headers["Content-Length"]))
                upstream = self.upstream
                try:
                    upstream.request(
                        "POST",
                        self.path,
                        body,
                        {
                            "Content-Type": "application/json",
                            "Authorization": self.headers.get("Authorization", ""),
                        },
                    )
                    response = upstream.getresponse()
                    payload = response.read()
                    try:
                        decoded = json.loads(payload)
                        error = "error" in decoded or any(
                            "error" in result for result in decoded.get("results", [])
                        )
                    except json.JSONDecodeError:
                        error = True
                    with lock:
                        observations.append({"status": response.status, "error": error})
                    self.send_response(response.status)
                    self.send_header("Content-Length", str(len(payload)))
                    self.end_headers()
                    self.wfile.write(payload)
                except (OSError, ssl.SSLError):
                    pass

        class Server(http.server.ThreadingHTTPServer):
            daemon_threads = True

            def handle_error(self, _request, _client_address):
                pass

        self.server = Server(("127.0.0.1", 0), Handler)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(
            cluster.directory / f"node-{node}.crt", cluster.directory / f"node-{node}.key"
        )
        tls.load_verify_locations(cluster.directory / "ca.crt")
        tls.verify_mode = ssl.CERT_REQUIRED
        self.server.socket = tls.wrap_socket(self.server.socket, server_side=True)
        self.port = self.server.server_port
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def clear(self):
        with self.lock:
            self.observations.clear()

    def snapshot(self):
        with self.lock:
            return list(self.observations)

    def close(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=3)
