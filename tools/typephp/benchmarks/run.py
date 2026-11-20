#!/usr/bin/env python3
"""Compare a qualified TypePHP artifact with its ordinary PHP in isolation."""

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
from datetime import datetime, timezone


ROOTS = [
    "bin", "src", "config", "templates", "translations", "vendor",
    "packages/baander-http-client/src", "packages/baander-redis-rate-limiter/src",
    "packages/baander-image-processing/src", "packages/baander-media-metadata/src",
    "packages/swoole-bundle/src", "packages/tsduck-php-ffi/src",
    "composer.json", "composer.lock", ".env", "tools/typephp/application-entry.php",
]


def command(*args, **kwargs):
    return subprocess.check_output(args, text=True, **kwargs).strip()


def digest(path):
    with path.open("rb") as source:
        return hashlib.file_digest(source, "sha256").hexdigest()


def tree_hashes(root):
    paths = set()
    for name in ROOTS:
        path = root / name
        if path.is_dir():
            paths.update(p for p in path.rglob("*") if p.is_file())
        elif path.is_file():
            paths.add(path)
    return {str(p.relative_to(root)): digest(p) for p in sorted(paths)
            if not str(p.relative_to(root)).startswith("config/secrets/")}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True,
                        help="The unchanged production source tree used to build the executable")
    parser.add_argument("--ordinary-image", required=True)
    parser.add_argument("--typephp-image", required=True)
    parser.add_argument("--binary-sha256", required=True)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--samples", type=int, default=30)
    parser.add_argument("--warmups", type=int, default=3)
    parser.add_argument("--iterations", type=int, default=100000)
    args = parser.parse_args()
    source = args.source.resolve()
    bench = Path(__file__).resolve().parent
    repo = bench.parents[2]
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=False)
    installed = json.loads((source / "vendor/composer/installed.json").read_text())
    if installed.get("dev") is not False:
        parser.error("--source must contain production-only Composer dependencies")
    subprocess.run(["git", "diff", "--quiet", args.source_commit, "--", *ROOTS], cwd=repo, check=True)
    hashes = tree_hashes(source)
    tracked = set(command("git", "ls-tree", "-r", "--name-only", args.source_commit,
                          "--", *ROOTS, cwd=repo).splitlines())
    mismatches = [p for p in tracked if p in hashes and digest(repo / p) != hashes[p]]
    missing = [p for p in tracked if p not in hashes and not p.startswith("config/secrets/")]
    extra = [p for p in hashes if p not in tracked and not p.startswith("vendor/")]
    if mismatches or missing or extra:
        raise RuntimeError(f"Source differs from commit: {sorted(mismatches + missing + extra)}")
    images = {label: command("docker", "image", "inspect", image, "--format", "{{.Id}}")
              for label, image in [("ordinary", args.ordinary_image), ("typephp", args.typephp_image)]}
    manifest = {
        "started_utc": datetime.now(timezone.utc).isoformat(),
        "source_commit": command("git", "rev-parse", args.source_commit, cwd=repo),
        "harness_commit": command("git", "rev-parse", "HEAD", cwd=repo),
        "harness_files": {p.name: digest(p) for p in sorted(bench.glob("*")) if p.is_file()},
        "checkout_status": command("git", "status", "--short", cwd=repo),
        "images": images, "expected_typephp_sha256": args.binary_sha256,
        "source_files": len(hashes),
        "source_tree_sha256": hashlib.sha256(json.dumps(hashes, sort_keys=True).encode()).hexdigest(),
        "matched_checkout_files": len(tracked),
        "composer_lock_sha256": digest(source / "composer.lock"),
        "production_packages": len(installed["packages"]),
        "samples": args.samples, "warmups": args.warmups, "iterations": args.iterations,
        "host": {"kernel": command("uname", "-srmo"), "cpu": json.loads(command("lscpu", "--json")),
                 "loadavg_before": Path("/proc/loadavg").read_text().strip()},
        "isolation": "One networkless disposable container; shared read-only production source; private tmpfs var; no CPU pinning",
    }
    (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    container = None
    donor = None
    try:
        with tempfile.TemporaryDirectory(prefix="baander-benchmark-") as scratch:
            scratch = Path(scratch)
            (scratch / "source-hashes.json").write_text(json.dumps(hashes))
            donor = command("docker", "create", "--entrypoint", "true", images["ordinary"])
            subprocess.run(["docker", "cp", f"{donor}:/usr/local/bin/php", str(scratch / "ordinary-php")], check=True)
            env = dict(os.environ, APP_SECRET=secrets.token_hex(32),
                       STREAM_URL_HMAC_SECRET=secrets.token_hex(32),
                       AUTH_TOTP_ENCRYPTION_KEY=base64.b64encode(secrets.token_bytes(32)).decode())
            container = command(
                "docker", "run", "--detach", "--network", "none", "--user", f"{os.getuid()}:{os.getgid()}",
                "--entrypoint", "sleep", "--workdir", "/var/www/html",
                "--env", "APP_ENV=prod", "--env", "APP_DEBUG=0", "--env", "XDEBUG_MODE=off",
                "--env", "OTEL_ENABLED=false", "--env", "BAANDER_TYPEPHP_QUALIFICATION=",
                "--env", "APP_SECRET", "--env", "STREAM_URL_HMAC_SECRET", "--env", "AUTH_TOTP_ENCRYPTION_KEY",
                "--volume", f"{source}:/var/www/html:ro,z", "--volume", f"{bench}:/bench:ro,z",
                "--volume", f"{scratch}:/input:ro,z", "--volume", f"{output}:/output:z",
                "--tmpfs", "/var/www/html/var:mode=1777", images["typephp"], "infinity", env=env)
            subprocess.run(["docker", "exec", container, "cp", "/input/ordinary-php", "/tmp/ordinary-php"], check=True)
            actual = command("docker", "exec", container, "sha256sum", "/usr/local/bin/php").split()[0]
            if actual != args.binary_sha256:
                raise RuntimeError("TypePHP executable does not match the expected artifact hash")
            # The package's file wrapper reads the archived bytes at embedded canonical paths.
            subprocess.run(["docker", "exec", container, "env", "PHP_INI_SCAN_DIR=", "PHPRC=/dev/null",
                            "/usr/local/bin/php", "-d", "auto_prepend_file=", "-f", "/bench/identity.php",
                            "--", "/input/source-hashes.json"], check=True)
            manifest["archived_source_comparison"] = "All listed bytes match the production source tree"
            manifest["ordinary_sha256"] = digest(scratch / "ordinary-php")
            (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
            subprocess.run(["docker", "exec", container, "python3", "/bench/measure.py",
                            "--normal", "/tmp/ordinary-php", "--typephp", "/usr/local/bin/php",
                            "--output", "/output/measurements.json", "--samples", str(args.samples),
                            "--warmups", str(args.warmups), "--iterations", str(args.iterations)], check=True)
            manifest["completed_utc"] = datetime.now(timezone.utc).isoformat()
            manifest["host"]["loadavg_after"] = Path("/proc/loadavg").read_text().strip()
            manifest["status"] = "passed"
    except Exception as error:
        manifest["status"] = "failed"
        manifest["failure"] = str(error)
        raise
    finally:
        for owned in [container, donor]:
            if owned:
                subprocess.run(["docker", "rm", "--force", owned], check=True, stdout=subprocess.DEVNULL)
        (output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")


if __name__ == "__main__":
    main()
