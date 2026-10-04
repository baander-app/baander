# SPDX-License-Identifier: Apache-2.0
"""Disposable real-voter cluster with peer-aware authenticated TLS fault links."""
import asyncio
import base64
import json
from pathlib import Path
import socket
import ssl
import subprocess
import threading
import time
from run_http_contract import LocalHTTPS, port
from run_transport_contract import certificate


class FaultNetwork:
    """Terminate/re-encrypt fixture TLS while retaining verified peer identity.

    Partitioning closes existing cross-group links and rejects subsequent links.
    No process pause or host firewall is used. All keys are disposable test keys.
    """

    def __init__(self, directory, count):
        self.directory = directory
        self.count = count
        self.loop = asyncio.new_event_loop()
        self.blocked = set()
        self.blackhole = False
        self.healed = asyncio.Event()
        self.healed.set()
        self.links = set()
        self.tasks = set()
        self.servers = []
        self.real_ports = [port() for _ in range(count)]
        self.ports = []
        self.server_contexts = []
        self.client_contexts = []
        for index in range(count):
            key, cert = directory / f"node-{index}.key", directory / f"node-{index}.crt"
            server = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            server.load_cert_chain(cert, key)
            server.load_verify_locations(directory / "ca.crt")
            server.verify_mode = ssl.CERT_REQUIRED
            client = ssl.create_default_context(cafile=str(directory / "ca.crt"))
            client.load_cert_chain(cert, key)
            self.server_contexts.append(server)
            self.client_contexts.append(client)
        self.thread = threading.Thread(target=self.loop.run_forever, daemon=True)
        self.thread.start()
        asyncio.run_coroutine_threadsafe(self.start(), self.loop).result(timeout=5)

    async def start(self):
        for index in range(self.count):

            async def incoming(reader, writer, destination=index):
                await self.forward(reader, writer, destination)

            server = await asyncio.start_server(
                incoming, "127.0.0.1", 0, ssl=self.server_contexts[index], ssl_handshake_timeout=2
            )
            self.servers.append(server)
            self.ports.append(server.sockets[0].getsockname()[1])

    async def forward(self, reader, writer, destination):
        task = asyncio.current_task()
        self.tasks.add(task)
        upstream = None
        link = None
        pumps = []
        try:
            subject = writer.get_extra_info("peercert").get("subject", ())
            common_names = [
                value for entry in subject for key, value in entry if key == "commonName"
            ]
            if len(common_names) != 1 or not common_names[0].startswith("node-"):
                return
            source = int(common_names[0][5:])
            if (
                source < 0
                or source >= self.count
                or (source, destination) in self.blocked
                or len(self.links) >= 256
            ):
                return
            upstream_reader, upstream = await asyncio.open_connection(
                "127.0.0.1",
                self.real_ports[destination],
                ssl=self.client_contexts[source],
                server_hostname="raft.registry.baander.app",
                ssl_handshake_timeout=2,
            )
            if (source, destination) in self.blocked:
                return
            link = (source, destination, writer, upstream)
            self.links.add(link)

            async def pump(input_stream, output_stream):
                while True:
                    body = await input_stream.read(65536)
                    if not body:
                        return
                    while (source, destination) in self.blocked and self.blackhole:
                        await self.healed.wait()
                    if output_stream.is_closing():
                        return
                    output_stream.write(body)
                    await output_stream.drain()

            pumps = [
                asyncio.create_task(pump(reader, upstream)),
                asyncio.create_task(pump(upstream_reader, writer)),
            ]
            await asyncio.wait(pumps, return_when=asyncio.FIRST_COMPLETED)
        except (OSError, ValueError, asyncio.CancelledError):
            pass
        finally:
            for pump in pumps:
                pump.cancel()
            if pumps:
                await asyncio.gather(*pumps, return_exceptions=True)
            if link:
                self.links.discard(link)
            writer.close()
            if upstream:
                upstream.close()
            self.tasks.discard(task)

    async def change_partition(self, group, blackhole=False):
        previous = self.blocked
        self.blackhole = blackhole
        self.blocked = (
            {
                (source, destination)
                for source in range(self.count)
                for destination in range(self.count)
                if (source in group) != (destination in group)
            }
            if group is not None
            else set()
        )
        for source, destination, incoming, outgoing in list(self.links):
            if ((source, destination) in self.blocked and not blackhole) or (
                group is None and (source, destination) in previous
            ):
                incoming.close()
                outgoing.close()
        if group is None:
            self.healed.set()
        else:
            self.healed.clear()

    def partition(self, group, blackhole=False):
        asyncio.run_coroutine_threadsafe(
            self.change_partition(set(group), blackhole), self.loop
        ).result(timeout=3)

    def heal(self):
        asyncio.run_coroutine_threadsafe(self.change_partition(None), self.loop).result(timeout=3)

    async def shutdown(self):
        for server in self.servers:
            server.close()
            await server.wait_closed()
        tasks = list(self.tasks)
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)

    def close(self):
        asyncio.run_coroutine_threadsafe(self.shutdown(), self.loop).result(timeout=5)
        self.loop.call_soon_threadsafe(self.loop.stop)
        self.thread.join(timeout=3)
        self.loop.close()


class Cluster:
    def __init__(self, directory, binary, count):
        self.directory = Path(directory)
        self.binary = binary
        self.count = count
        self.processes = []
        self.logs = []
        subprocess.run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                str(self.directory / "ca.key"),
                "-out",
                str(self.directory / "ca.crt"),
                "-days",
                "1",
                "-addext",
                "basicConstraints=critical,CA:TRUE",
                "-addext",
                "keyUsage=critical,keyCertSign,cRLSign",
                "-subj",
                "/CN=Baander disposable cluster CA",
            ],
            check=True,
            capture_output=True,
        )
        self.client_key, self.client_cert = certificate(self.directory, "client")
        self.api_key, self.api_cert = certificate(self.directory, "api", "api.registry.baander.app")
        for index in range(count):
            name = f"node-{index}"
            key, csr, cert = (
                self.directory / (name + suffix) for suffix in (".key", ".csr", ".crt")
            )
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
                    str(csr),
                    "-subj",
                    f"/CN={name}",
                ],
                check=True,
                capture_output=True,
            )
            extension = self.directory / (name + ".ext")
            extension.write_text(
                f"subjectAltName=DNS:raft.registry.baander.app,DNS:node-{index}.rqlite.baander.app\nextendedKeyUsage=serverAuth,clientAuth\n"
            )
            subprocess.run(
                [
                    "openssl",
                    "x509",
                    "-req",
                    "-in",
                    str(csr),
                    "-CA",
                    str(self.directory / "ca.crt"),
                    "-CAkey",
                    str(self.directory / "ca.key"),
                    "-CAcreateserial",
                    "-out",
                    str(cert),
                    "-days",
                    "1",
                    "-extfile",
                    str(extension),
                ],
                check=True,
                capture_output=True,
            )
        self.network = FaultNetwork(self.directory, count)
        self.http_ports = [port() for _ in range(count)]
        (self.directory / "password").write_text("disposable-cluster-secret\n")
        (self.directory / "auth.json").write_text(
            json.dumps(
                [
                    {
                        "username": "registry",
                        "password": "disposable-cluster-secret",
                        "perms": ["all"],
                    }
                ]
            )
        )
        self.tls = ssl.create_default_context(cafile=str(self.directory / "ca.crt"))
        self.tls.load_cert_chain(self.client_cert, self.client_key)
        self.api_tls = ssl.create_default_context(cafile=str(self.directory / "ca.crt"))

    def start_node(self, index):
        log = open(self.directory / f"node-{index}.log", "a")
        self.logs.append(log)
        command = [
            self.binary,
            "-node-id",
            f"node-{index}",
            "-http-addr",
            f"127.0.0.1:{self.http_ports[index]}",
            "-http-adv-addr",
            f"node-{index}.rqlite.baander.app:{self.http_ports[index]}",
            "-raft-addr",
            f"127.0.0.1:{self.network.real_ports[index]}",
            "-raft-adv-addr",
            f"127.0.0.1:{self.network.ports[index]}",
            "-http-cert",
            str(self.directory / f"node-{index}.crt"),
            "-http-key",
            str(self.directory / f"node-{index}.key"),
            "-http-ca-cert",
            str(self.directory / "ca.crt"),
            "-http-verify-client",
            "-node-cert",
            str(self.directory / f"node-{index}.crt"),
            "-node-key",
            str(self.directory / f"node-{index}.key"),
            "-node-ca-cert",
            str(self.directory / "ca.crt"),
            "-node-verify-client",
            "-node-verify-server-name",
            "raft.registry.baander.app",
            "-auth",
            str(self.directory / "auth.json"),
        ]
        if index:
            command += [
                "-join",
                f"127.0.0.1:{self.network.ports[0]}",
                "-join-as",
                "registry",
                "-join-attempts",
                "20",
                "-join-interval",
                "200ms",
            ]
        command.append(str(self.directory / f"data-{index}"))
        process = subprocess.Popen(command, stdout=log, stderr=subprocess.STDOUT)
        if index < len(self.processes):
            self.processes[index] = process
        else:
            self.processes.append(process)

    def request(self, index, target, statements=None):
        connection = LocalHTTPS(
            f"node-{index}.rqlite.baander.app", self.http_ports[index], context=self.tls, timeout=3
        )
        try:
            headers = {
                "Authorization": "Basic "
                + base64.b64encode(b"registry:disposable-cluster-secret").decode(),
                "Content-Type": "application/json",
            }
            connection.request(
                "POST" if statements is not None else "GET",
                target,
                json.dumps(statements) if statements is not None else None,
                headers,
            )
            response = connection.getresponse()
            payload = response.read()
            try:
                body = json.loads(payload)
            except json.JSONDecodeError:
                body = {"nonJson": True}
            return response.status, body
        finally:
            connection.close()

    def wait_leader(self, candidates=None, seconds=15):
        until = time.monotonic() + seconds
        while time.monotonic() < until:
            for index in range(self.count) if candidates is None else candidates:
                try:
                    status, body = self.request(index, "/status")
                    if status == 200 and body["store"]["raft"]["state"] == "Leader":
                        return index
                except (OSError, KeyError, json.JSONDecodeError):
                    pass
            time.sleep(0.05)
        raise AssertionError("Cluster did not elect a leader within the bound.")

    def start(self):
        self.start_node(0)
        self.wait_leader([0])
        for index in range(1, self.count):
            self.start_node(index)
        until = time.monotonic() + 20
        while time.monotonic() < until:
            leader = self.wait_leader()
            status, nodes = self.request(leader, "/nodes")
            if (
                status == 200
                and len(nodes) == self.count
                and all(value.get("voter") for value in nodes.values())
            ):
                return leader
            time.sleep(0.1)
        raise AssertionError("Not all expected voters joined.")

    def close(self):
        for process in self.processes:
            if process.poll() is None:
                process.terminate()
        for process in self.processes:
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=3)
        self.network.close()
        for log in self.logs:
            log.close()
