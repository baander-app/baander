#!/usr/bin/env python3
"""Exercise destructive operations against two disposable backend stacks."""

import argparse
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import uuid


DATABASE_URL = "postgresql://baander:test-only@postgres:5432/functional_test?serverVersion=18&charset=utf8"
REDIS_URL = "redis://default:test-only@redis:6379"
CACHE_PATH = "/var/www/html/var/cache/test/stack-isolation-marker"
SOCKET_PATH = "/tmp/baander-stack-isolation.sock"
PHP_CONNECT = """
$database = parse_url(getenv('DATABASE_URL'));
$db = new PDO('pgsql:host=' . $database['host'] . ';port=' . $database['port'] . ';dbname=' . ltrim($database['path'], '/'), $database['user'], $database['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$address = parse_url(getenv('REDIS_URL'));
$redis = new Redis();
$redis->connect($address['host'], $address['port'], 2);
$redis->auth([$address['user'], $address['pass']]);
"""


class RegressionInterrupted(RuntimeError):
    """Avoid InterruptedError, which subprocess I/O retries internally."""


class Regression:
    def __init__(self, artifacts, timeout):
        self.artifacts = artifacts
        self.deadline = time.monotonic() + timeout
        self.prefix = f"baander-isolation-{uuid.uuid4().hex[:12]}"
        self.networks = []
        self.containers = []
        self.results = []
        self.sequence = 0

    def command(self, *args, timeout=30, check=True):
        remaining = self.deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("Stack isolation regression exceeded its deadline")
        result = subprocess.run(args, text=True, capture_output=True,
                                timeout=min(timeout, remaining))
        self.sequence += 1
        (self.artifacts / f"command-{self.sequence:03d}.log").write_text(
            json.dumps(list(args)) + "\n" + result.stdout + result.stderr)
        if check and result.returncode:
            raise RuntimeError(f"Command failed ({result.returncode}): {args[0:4]}; see artifacts")
        return result

    def php(self, stack, code):
        return self.command("docker", "exec", f"{self.prefix}-{stack}-app",
                            "php", "-d", "memory_limit=512M", "-r", code).stdout.strip()

    def expect(self, name, actual, expected):
        passed = actual == expected
        self.results.append({"check": name, "passed": passed, "actual": actual,
                             "expected": expected})
        if not passed:
            raise AssertionError(f"{name}: expected {expected!r}, received {actual!r}")

    def start(self, stack):
        network = f"{self.prefix}-{stack}"
        self.networks.append(network)
        self.command("docker", "network", "create", "--internal", network)
        services = [
            ("postgres", os.environ.get("BAANDER_TEST_POSTGRES_IMAGE", "baander-database:latest"),
             ["-e", "POSTGRES_USER=baander", "-e", "POSTGRES_PASSWORD=test-only",
              "-e", "POSTGRES_DB=functional_test"]),
            ("redis", os.environ.get("BAANDER_TEST_REDIS_IMAGE", "redis/redis-stack-server:edge"),
             ["-e", "REDIS_ARGS=--requirepass test-only"]),
            ("app", os.environ.get("BAANDER_TEST_IMAGE", "baander-ci-prewarm:local"),
             ["--entrypoint", "sh", "-e", "APP_ENV=test", "-e", f"DATABASE_URL={DATABASE_URL}",
              "-e", f"OUTBOX_TEST_DATABASE_URL={DATABASE_URL}", "-e", f"REDIS_URL={REDIS_URL}",
              "-e", "REDIS_PASSWORD=test-only", "-e", f"MESSENGER_TEST_REDIS_DSN={REDIS_URL}",
              "-e", f"MESSENGER_TRANSPORT_DSN={REDIS_URL}/messages"]),
        ]
        for service, image, options in services:
            name = f"{network}-{service}"
            self.containers.append(name)
            self.command("docker", "run", "-d", "--name", name, "--network", network,
                         "--network-alias", service, *options, image,
                         *(["-c", "exec sleep infinity"] if service == "app" else []), timeout=90)
        for _ in range(60):
            ready = self.command("docker", "exec", f"{network}-app", "php", "-d", "memory_limit=512M", "-r",
                                 PHP_CONNECT + "echo $redis->ping() ? 'ready' : 'failed';",
                                 check=False)
            if ready.returncode == 0 and ready.stdout == "ready":
                break
            time.sleep(1)
        else:
            raise RuntimeError(f"Stack {stack} did not become ready")

    def seed(self, stack):
        self.php(stack, PHP_CONNECT + f"""
$db->exec('CREATE TABLE isolation_probe (marker text NOT NULL)');
$db->exec("INSERT INTO isolation_probe VALUES ('{stack}')");
$redis->set('isolation-probe', '{stack}');
mkdir(dirname('{CACHE_PATH}'), 0777, true);
file_put_contents('{CACHE_PATH}', '{stack}');
""")
        self.command("docker", "exec", "-d", f"{self.prefix}-{stack}-app", "php", "-d", "memory_limit=512M", "-r", f"""
$server = stream_socket_server('unix://{SOCKET_PATH}', $error, $message);
if (!$server) {{ throw new RuntimeException($message); }}
while ($connection = stream_socket_accept($server, -1)) {{ fwrite($connection, '{stack}'); fclose($connection); }}
""")
        for _ in range(30):
            if self.php(stack, f"echo file_exists('{SOCKET_PATH}') ? 'ready' : 'waiting';") == "ready":
                return
            time.sleep(0.1)
        raise RuntimeError(f"Stack {stack} socket did not become ready")

    def probe(self, stack):
        return self.php(stack, PHP_CONNECT + "echo $db->query('SELECT marker FROM isolation_probe')->fetchColumn() . ':' . $redis->get('isolation-probe');")

    def run(self):
        for stack in ("left", "right"):
            self.start(stack)
            self.seed(stack)
            self.expect(f"{stack} initial markers", self.probe(stack), f"{stack}:{stack}")
            # proc_open receives no environment override: descendants must inherit every DSN.
            child = PHP_CONNECT + "echo json_encode([getenv('DATABASE_URL'), getenv('OUTBOX_TEST_DATABASE_URL'), getenv('REDIS_URL'), getenv('MESSENGER_TEST_REDIS_DSN'), getenv('MESSENGER_TRANSPORT_DSN'), $db->query('SELECT marker FROM isolation_probe')->fetchColumn(), $redis->get('isolation-probe')]);"
            quoted_child = "'" + child.replace("\\", "\\\\").replace("'", "\\'") + "'"
            parent = "$p = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', '-r', " + quoted_child + "], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); echo stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($p)) { fwrite(STDERR, $errors); exit(1); }"
            self.expect(f"{stack} nested PHP environment and services", json.loads(self.php(stack, parent)),
                        [DATABASE_URL, DATABASE_URL, REDIS_URL, REDIS_URL, REDIS_URL + "/messages", stack, stack])

        self.php("left", PHP_CONNECT + "$db->exec('TRUNCATE isolation_probe'); echo $db->query('SELECT count(*) FROM isolation_probe')->fetchColumn();")
        self.expect("left database truncated", self.php("left", PHP_CONNECT + "echo $db->query('SELECT count(*) FROM isolation_probe')->fetchColumn();"), "0")
        self.expect("peer survives database truncate", self.probe("right"), "right:right")
        self.command("docker", "stop", "--time", "1", f"{self.prefix}-left-postgres")
        failed = self.command("docker", "exec", f"{self.prefix}-left-app", "php", "-d", "memory_limit=512M", "-r", PHP_CONNECT, check=False)
        self.expect("left database outage observed", failed.returncode != 0, True)
        self.expect("peer survives database outage", self.probe("right"), "right:right")

        redis = "$redis = new Redis(); $redis->connect('redis', 6379, 2); $redis->auth(['default', 'test-only']);"
        self.php("left", redis + "$redis->flushAll();")
        self.expect("left Redis flushed", self.php("left", redis + "echo $redis->dbSize();"), "0")
        self.expect("peer survives Redis FLUSHALL", self.probe("right"), "right:right")
        self.php("left", redis + "$redis->rawCommand('CLIENT', 'PAUSE', '5000', 'ALL');")
        started = time.monotonic()
        self.expect("peer survives Redis CLIENT PAUSE", self.probe("right"), "right:right")
        self.expect("peer Redis responds during pause", time.monotonic() - started < 2, True)
        self.php("left", redis + "$redis->setOption(Redis::OPT_READ_TIMEOUT, 10); echo $redis->ping() ? 'ready' : 'failed';")
        self.expect("left Redis pause observed", time.monotonic() - started >= 3, True)

        for stack in ("left", "right"):
            self.expect(f"{stack} identical cache path", self.php(stack, f"echo file_get_contents('{CACHE_PATH}');"), stack)
            self.expect(f"{stack} identical socket path", self.php(stack, f"$s = stream_socket_client('unix://{SOCKET_PATH}'); echo stream_get_contents($s);"), stack)
        self.php("left", f"unlink('{CACHE_PATH}'); unlink('{SOCKET_PATH}');")
        self.expect("left cache and socket removed", self.php("left", f"echo (!file_exists('{CACHE_PATH}') && !file_exists('{SOCKET_PATH}')) ? 'removed' : 'present';"), "removed")
        self.expect("peer cache survives deletion", self.php("right", f"echo file_get_contents('{CACHE_PATH}');"), "right")
        self.expect("peer socket survives deletion", self.php("right", f"$s = stream_socket_client('unix://{SOCKET_PATH}'); echo stream_get_contents($s);"), "right")

    def cleanup(self):
        errors = []
        for container in self.containers:
            for operation in ("logs", "inspect"):
                try:
                    result = subprocess.run(["docker", operation, container], text=True,
                                            capture_output=True, timeout=15)
                    (self.artifacts / f"{container}-{operation}.log").write_text(result.stdout + result.stderr)
                except Exception as failure:
                    errors.append(f"{operation} {container}: {failure}")
            self.remove("container", container, ["rm", "-f"], errors)
        for network in self.networks:
            self.remove("network", network, ["network", "rm"], errors)
        return errors

    @staticmethod
    def remove(kind, name, operation, errors):
        try:
            result = subprocess.run(["docker", *operation, name], text=True,
                                    capture_output=True, timeout=30)
            if result.returncode and f"No such {kind}" not in result.stderr:
                errors.append(result.stderr.strip())
        except Exception as failure:
            errors.append(f"remove {kind} {name}: {failure}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifacts", type=Path)
    parser.add_argument("--timeout", type=int, default=300)
    args = parser.parse_args()
    if args.timeout < 1:
        parser.error("--timeout must be a positive number of seconds")
    artifacts = args.artifacts or Path(tempfile.mkdtemp(prefix="baander-stack-isolation-"))
    artifacts.mkdir(parents=True, exist_ok=True)
    regression = Regression(artifacts, args.timeout)
    def interrupted(signum, _frame):
        raise RegressionInterrupted(f"Interrupted by signal {signum}")
    for signum in (signal.SIGINT, signal.SIGTERM):
        signal.signal(signum, interrupted)
    error = None
    try:
        regression.run()
    except Exception as failure:
        error = str(failure)
    finally:
        # A repeated interruption must not interrupt resource removal.
        for signum in (signal.SIGINT, signal.SIGTERM):
            signal.signal(signum, signal.SIG_IGN)
        try:
            cleanup_errors = regression.cleanup()
        except Exception as failure:
            cleanup_errors = [str(failure)]
        (artifacts / "results.json").write_text(json.dumps({"checks": regression.results,
            "error": error, "cleanup_errors": cleanup_errors}, indent=2) + "\n")
    if error or cleanup_errors:
        print(f"Stack isolation failed: {error or cleanup_errors}; artifacts: {artifacts}", file=sys.stderr)
        return 1
    print(f"Stack isolation passed: {len(regression.results)} checks; artifacts: {artifacts}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
