#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Disposable image qualification; never opens operator data or existing volumes."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid


def docker(*arguments, check=True, input=None):
    return subprocess.run(
        ["docker", *arguments], check=check, capture_output=True, text=True, input=input
    )


def issue(directory, name, dns=None):
    subprocess.run(
        [
            "openssl",
            "req",
            "-new",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            str(directory / f"{name}.key"),
            "-out",
            str(directory / f"{name}.csr"),
            "-subj",
            f"/CN={name}",
        ],
        check=True,
        capture_output=True,
    )
    extension = directory / f"{name}.ext"
    extension.write_text(
        "extendedKeyUsage=serverAuth,clientAuth\n" + (f"subjectAltName={dns}\n" if dns else "")
    )
    subprocess.run(
        [
            "openssl",
            "x509",
            "-req",
            "-in",
            str(directory / f"{name}.csr"),
            "-CA",
            str(directory / "ca.crt"),
            "-CAkey",
            str(directory / "ca.key"),
            "-CAcreateserial",
            "-out",
            str(directory / f"{name}.crt"),
            "-days",
            "1",
            "-extfile",
            str(extension),
        ],
        check=True,
        capture_output=True,
    )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--api-image", required=True)
    parser.add_argument("--rqlite-image", required=True)
    parser.add_argument("--guards-only", action="store_true")
    args = parser.parse_args()
    prefix = "baander-registry-test-" + uuid.uuid4().hex[:12]
    containers, volumes = [], []
    data_volumes = []
    password = "disposable-container-secret"
    registration_credential = "bc" * 32
    network_created = False
    with tempfile.TemporaryDirectory(prefix=prefix) as temporary:
        directory = Path(temporary)
        directory.chmod(0o755)
        try:
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
                    "-subj",
                    "/CN=Baander disposable container CA",
                    "-addext",
                    "basicConstraints=critical,CA:TRUE",
                    "-addext",
                    "keyUsage=critical,keyCertSign,cRLSign",
                ],
                check=True,
                capture_output=True,
            )
            issue(directory, "api", "DNS:api.registry.baander.app")
            issue(directory, "client")
            auth = [
                {"username": "registry", "password": password, "perms": ["query", "execute"]},
                {"username": "registry-cluster", "password": password, "perms": ["join"]},
                {"username": "registry-observer", "password": password, "perms": ["status"]},
            ]
            (directory / "password").write_text(password + "\n")
            (directory / "database-curl.conf").write_text(f'user = "registry:{password}"\n')
            (directory / "status-curl.conf").write_text(f'user = "registry-observer:{password}"\n')
            (directory / "auth.json").write_text(json.dumps(auth))
            secrets = []
            for index in range(5):
                issue(
                    directory,
                    f"node-{index}",
                    f"DNS:raft.registry.baander.app,DNS:node-{index}.rqlite.baander.app",
                )
                target = directory / f"secrets-{index}"
                target.mkdir(mode=0o755)
                for source, destination in [
                    ("ca.crt", "ca.crt"),
                    (f"node-{index}.crt", "node.crt"),
                    (f"node-{index}.key", "node.key"),
                    ("auth.json", "auth.json"),
                ]:
                    shutil.copyfile(directory / source, target / destination)
                    (target / destination).chmod(0o644)  # Disposable fixture keys only.
                secrets.append(target)
            docker("network", "create", prefix)
            network_created = True

            def copy_fixture(source, suffix):
                volume = prefix + "-fixture-" + suffix
                helper = prefix + "-copy-" + suffix
                docker("volume", "create", volume)
                volumes.append(volume)
                containers.append(helper)
                docker(
                    "create",
                    "--name",
                    helper,
                    "-v",
                    f"{volume}:/fixture",
                    "--entrypoint",
                    "/bin/true",
                    args.rqlite_image,
                )
                docker("cp", str(source) + "/.", helper + ":/fixture")
                docker("rm", helper)
                containers.remove(helper)
                return volume

            secret_volumes = [
                copy_fixture(source, str(index)) for index, source in enumerate(secrets)
            ]
            peers = ",".join(f"node-{index}.rqlite.baander.app:4002" for index in range(5))

            def start(index, mode, extra=(), check=True, detached=True):
                name = prefix + f"-node-{index}"
                if detached:
                    containers.append(name)
                command = ["run", "--name", name] if detached else ["run", "--rm"]
                command += ["-d"] if detached else []
                command += [
                    "--network",
                    prefix,
                    "--network-alias",
                    f"node-{index}.rqlite.baander.app",
                    "--read-only",
                    "--memory",
                    "352m",
                    "--cpus",
                    "0.75",
                    "--tmpfs",
                    "/tmp:rw,noexec,nosuid,nodev,size=32m",
                    "--cap-drop",
                    "ALL",
                    "--security-opt",
                    "no-new-privileges",
                    "-v",
                    f"{data_volumes[index]}:/var/lib/rqlite",
                    "-v",
                    f"{secret_volumes[index]}:/run/secrets/rqlite:ro",
                ]
                values = {
                    "RQLITE_NODE_ID": f"node-{index}",
                    "RQLITE_PRIVATE_BIND_IP": "10.42.0.11",
                    "RQLITE_HTTP_ADVERTISE": f"node-{index}.rqlite.baander.app:4001",
                    "RQLITE_RAFT_ADVERTISE": f"node-{index}.rqlite.baander.app:4002",
                    "RQLITE_JOIN_ADDRESSES": peers,
                    "RQLITE_START_MODE": mode,
                }
                values.update(dict(extra))
                for key, value in values.items():
                    command += ["-e", f"{key}={value}"]
                return docker(*command, args.rqlite_image, check=check)

            for index in range(5):
                volume = prefix + f"-data-{index}"
                docker("volume", "create", volume)
                volumes.append(volume)
                data_volumes.append(volume)
            for mode, changes, expected in [
                ("restart", (), "restart requires existing voter state"),
                ("bootstrap", (("RQLITE_EXPECTED_VOTERS", "3"),), "requires five initial voters"),
                ("join-new", (("RQLITE_JOIN_ADDRESSES", ""),), "requires explicit peer inventory"),
                (
                    "join-new",
                    (("RQLITE_JOIN_ADDRESSES", "node-0.rqlite.baander.app:4002"),),
                    "peer other than this node",
                ),
                (
                    "bootstrap",
                    (("RQLITE_PRIVATE_BIND_IP", "0.0.0.0"),),
                    "RFC1918 private interface",
                ),
            ]:
                result = start(0, mode, changes, check=False, detached=False)
                assert result.returncode == 64 and expected in result.stderr, result.stderr
                assert password not in result.stderr
            print(
                "PASS: empty restart, reduced voters, missing join peers and public DB binding rejected",
                flush=True,
            )
            start(0, "bootstrap")
            until = time.monotonic() + 10
            while time.monotonic() < until:
                if (
                    docker(
                        "exec",
                        prefix + "-node-0",
                        "test",
                        "-s",
                        "/var/lib/rqlite/raft.db",
                        check=False,
                    ).returncode
                    == 0
                ):
                    break
                time.sleep(0.1)
            else:
                raise AssertionError("Incomplete bootstrap never created native Raft storage")
            docker("stop", "--time", "15", prefix + "-node-0")
            docker("rm", prefix + "-node-0")
            containers.remove(prefix + "-node-0")
            start(0, "restart")
            until = time.monotonic() + 5
            while time.monotonic() < until:
                logs = docker("logs", prefix + "-node-0")
                assert (
                    "bootstrapping single new node" not in logs.stdout + logs.stderr
                ), "Incomplete five-voter bootstrap restarted as a competing single-voter cluster"
                if (
                    docker(
                        "inspect", "--format", "{{.State.Running}}", prefix + "-node-0"
                    ).stdout.strip()
                    == "false"
                ):
                    break
                time.sleep(0.1)
            else:
                raise AssertionError("Incomplete bootstrap restart did not fail closed")
            assert (
                docker(
                    "inspect", "--format", "{{.State.ExitCode}}", prefix + "-node-0"
                ).stdout.strip()
                != "0"
            )
            docker("rm", prefix + "-node-0")
            containers.remove(prefix + "-node-0")
            new_volume = prefix + "-fresh-data-0"
            docker("volume", "create", new_volume)
            volumes.append(new_volume)
            data_volumes[0] = new_volume
            print(
                "PASS: incomplete five-voter bootstrap cannot restart as a new single-voter cluster",
                flush=True,
            )
            if args.guards_only:
                return
            for index in range(5):
                start(index, "bootstrap")
            config = {
                "api": {
                    "address": "0.0.0.0",
                    "port": 9502,
                    "certificate": "/fixture/api.crt",
                    "key": "/fixture/api.key",
                },
                "database": {
                    "endpoints": [
                        {"url": f"https://node-{index}.rqlite.baander.app:4001"}
                        for index in range(5)
                    ],
                    "ca": "/fixture/ca.crt",
                    "certificate": "/fixture/client.crt",
                    "key": "/fixture/client.key",
                    "username": "registry",
                    "passwordFile": "/fixture/password",
                    "connections": 2,
                },
            }
            (directory / "config.json").write_text(json.dumps(config))
            api_directory = directory / "api-runtime"
            api_directory.mkdir(mode=0o755)
            for filename in [
                "config.json",
                "api.crt",
                "api.key",
                "client.crt",
                "client.key",
                "ca.crt",
                "password",
                "database-curl.conf",
                "status-curl.conf",
            ]:
                shutil.copyfile(directory / filename, api_directory / filename)
                (api_directory / filename).chmod(0o644)  # Disposable fixture keys only.
            api_name = prefix + "-api"
            api_volume = copy_fixture(api_directory, "api")
            containers.append(api_name)
            docker(
                "run",
                "-d",
                "--name",
                api_name,
                "--network",
                prefix,
                "--network-alias",
                "api.registry.baander.app",
                "--read-only",
                "--memory",
                "96m",
                "--cpus",
                "0.25",
                "--cap-drop",
                "ALL",
                "--security-opt",
                "no-new-privileges",
                "-v",
                f"{api_volume}:/fixture:ro",
                args.api_image,
                "--config",
                "/fixture/config.json",
            )

            def request(method, target, body=None):
                command = [
                    "run",
                    "--rm",
                    "-i",
                    "--network",
                    prefix,
                    "-v",
                    f"{api_volume}:/fixture:ro",
                    "--entrypoint",
                    "curl",
                    args.api_image,
                    "--silent",
                    "--show-error",
                    "--fail-with-body",
                    "--max-time",
                    "3",
                    "--cacert",
                    "/fixture/ca.crt",
                    "--request",
                    method,
                    "--write-out",
                    "\n%{http_code}",
                ]
                if body is not None:
                    command += ["--header", "Content-Type: application/json", "--data-binary", "@-"]
                command += ["https://api.registry.baander.app:9502" + target]
                response = docker(
                    *command, check=False, input=json.dumps(body) if body is not None else None
                )
                if response.returncode not in (0, 22):
                    raise OSError("Disposable TLS probe could not reach the API")
                payload, status = response.stdout.rsplit("\n", 1)
                return int(status), json.loads(payload)

            def ready():
                until = time.monotonic() + 45
                while time.monotonic() < until:
                    try:
                        if request("GET", "/ready")[0] == 200:
                            return
                    except (OSError, ValueError):
                        pass
                    time.sleep(0.1)
                raise AssertionError("Five-voter container cluster never became ready")

            ready()
            value = {
                "publicId": "container-contract",
                "url": "https://container-contract.baander.app",
                "name": "container",
                "version": "1",
                "apiKey": registration_credential,
            }
            status, body = request("POST", "/api/servers/register", value)
            assert status == 200, body
            assert "apiKey" not in body["data"] and "credential_digest" not in body["data"]
            revision = body["data"]["revision"]
            assert request("GET", "/api/servers/container-contract")[0] == 200
            print(
                "PASS: five native TLS voters, nonroot read-only API registration and lookup",
                flush=True,
            )
            for index in range(5):
                name = prefix + f"-node-{index}"
                docker("stop", "--time", "15", name)
                docker("rm", name)
                containers.remove(name)
                rejected = start(index, "bootstrap", check=False, detached=False)
                assert rejected.returncode == 64 and "empty data directory" in rejected.stderr
                if index == 0:
                    rejected = start(
                        index,
                        "restart",
                        (("RQLITE_NODE_ID", "different-node"),),
                        check=False,
                        detached=False,
                    )
                    assert (
                        rejected.returncode == 64 and "different voter identity" in rejected.stderr
                    )
                    docker(
                        "run",
                        "--rm",
                        "-v",
                        f"{data_volumes[index]}:/var/lib/rqlite",
                        "--entrypoint",
                        "/bin/sh",
                        args.rqlite_image,
                        "-c",
                        "mkdir -p /var/lib/rqlite/raft && printf '{}' > /var/lib/rqlite/raft/peers.json",
                    )
                    rejected = start(index, "restart", check=False, detached=False)
                    assert rejected.returncode == 64 and "manual quorum recovery" in rejected.stderr
                    docker(
                        "run",
                        "--rm",
                        "-v",
                        f"{data_volumes[index]}:/var/lib/rqlite",
                        "--entrypoint",
                        "/bin/sh",
                        args.rqlite_image,
                        "-c",
                        "rm /var/lib/rqlite/raft/peers.json",
                    )
                start(index, "restart")
            ready()
            status, body = request("GET", "/api/servers/container-contract")
            assert status == 200 and body["data"]["revision"] == revision, body
            nodes = docker(
                "run",
                "--rm",
                "--network",
                prefix,
                "-v",
                f"{api_volume}:/fixture:ro",
                "--entrypoint",
                "curl",
                args.api_image,
                "--silent",
                "--show-error",
                "--fail",
                "--max-time",
                "5",
                "--config",
                "/fixture/status-curl.conf",
                "--cacert",
                "/fixture/ca.crt",
                "--cert",
                "/fixture/client.crt",
                "--key",
                "/fixture/client.key",
                "https://node-0.rqlite.baander.app:4001/nodes",
            )
            membership = json.loads(nodes.stdout)
            assert set(membership) == {f"node-{index}" for index in range(5)}, membership
            assert all(node["voter"] for node in membership.values()), membership
            replacement_name = prefix + "-node-4"
            docker("stop", "--time", "15", replacement_name)
            docker("rm", replacement_name)
            containers.remove(replacement_name)
            replacement_volume = prefix + "-replacement-data-4"
            docker("volume", "create", replacement_volume)
            volumes.append(replacement_volume)
            data_volumes[4] = replacement_volume
            start(4, "join-new")
            until = time.monotonic() + 30
            while time.monotonic() < until:
                logs = docker("logs", replacement_name)
                if "successfully joined cluster" in logs.stdout + logs.stderr:
                    break
                time.sleep(0.1)
            else:
                raise AssertionError("Explicit replacement did not join the existing cluster")
            query = docker(
                "run",
                "--rm",
                "-i",
                "--network",
                prefix,
                "-v",
                f"{api_volume}:/fixture:ro",
                "--entrypoint",
                "curl",
                args.api_image,
                "--silent",
                "--show-error",
                "--fail",
                "--max-time",
                "5",
                "--config",
                "/fixture/database-curl.conf",
                "--cacert",
                "/fixture/ca.crt",
                "--cert",
                "/fixture/client.crt",
                "--key",
                "/fixture/client.key",
                "--data-binary",
                "@-",
                "https://node-4.rqlite.baander.app:4001/db/query?level=linearizable&associative",
                input=json.dumps(
                    ["SELECT revision FROM registries WHERE public_id='container-contract'"]
                ),
            )
            rows = json.loads(query.stdout)["results"][0]["rows"]
            assert rows == [{"revision": revision}], rows
            print(
                "PASS: explicit empty replacement joins existing five-voter membership and reads acknowledged revision",
                flush=True,
            )
            for name in containers:
                logs = docker("logs", name)
                assert password not in logs.stdout + logs.stderr
                assert value["apiKey"] not in logs.stdout + logs.stderr
                assert (
                    docker("inspect", "--format", "{{.Config.User}}", name).stdout.strip()
                    == "10001:10001"
                )
            docker("stop", "--time", "7", api_name)
            assert (
                docker("inspect", "--format", "{{.State.ExitCode}}", api_name).stdout.strip() == "0"
            )
            print(
                "PASS: bootstrap reuse rejected, rolling restart retains acknowledged revision, API SIGTERM exits cleanly",
                flush=True,
            )
        except BaseException:
            for name in containers:
                result = docker("logs", "--tail", "80", name, check=False)
                diagnostic = (result.stdout + result.stderr).replace(password, "[REDACTED]")
                diagnostic = diagnostic.replace(registration_credential, "[REDACTED]")
                (Path("/tmp") / (name + ".log")).write_text(diagnostic)
            raise
        finally:
            failures = []
            for name in reversed(containers):
                result = docker("rm", "-f", name, check=False)
                if result.returncode:
                    failures.append("container cleanup failed")
            for volume in volumes:
                result = docker("volume", "rm", volume, check=False)
                if result.returncode:
                    failures.append("disposable volume cleanup failed")
            if network_created:
                result = docker("network", "rm", prefix, check=False)
                if result.returncode:
                    failures.append("disposable network cleanup failed")
            if failures:
                raise RuntimeError("; ".join(failures))


if __name__ == "__main__":
    main()
