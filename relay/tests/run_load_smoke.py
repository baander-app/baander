#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Bounded local registry workload smoke test, separate from regional acceptance."""

import argparse
from concurrent.futures import ThreadPoolExecutor, wait, FIRST_COMPLETED
import json
import math
from pathlib import Path
import tempfile
import threading
import time

from cluster_support import Cluster
from run_cluster_contract import Api, close_resources, registration


def percentile(samples, percentage):
    ordered = sorted(samples)
    return ordered[max(0, math.ceil(len(ordered) * percentage / 100) - 1)]


def process_rss_bytes(process):
    for line in Path(f"/proc/{process.pid}/status").read_text().splitlines():
        if line.startswith("VmRSS:"):
            return int(line.split()[1]) * 1024
    raise AssertionError("Registry API RSS is unavailable.")


def run_phase(apis, registrations, seconds, lookup_rate, heartbeat_rate):
    schedule = [
        (index / lookup_rate, "lookup", index)
        for index in range(math.ceil(seconds * lookup_rate))
        if index / lookup_rate < seconds
    ]
    schedule += [
        (index / heartbeat_rate, "heartbeat", index)
        for index in range(math.ceil(seconds * heartbeat_rate))
        if index / heartbeat_rate < seconds
    ]
    schedule.sort()

    def request(kind, index):
        api = apis[index % len(apis)]
        item = registrations[index % len(registrations)]
        started = time.perf_counter()
        if kind == "lookup":
            status, body, _ = api.request("GET", "/api/servers/" + item["publicId"])
        else:
            status, body, _ = api.request("POST", "/api/servers/register", item, enrollment=False)
        latency = time.perf_counter() - started
        if status != 200 or body.get("data", {}).get("publicId") != item["publicId"]:
            raise AssertionError(f"{kind} failed for {item['publicId']}: HTTP {status}")
        return kind, latency

    latency_by_kind = {"lookup": [], "heartbeat": []}
    pending = set()
    maximum_pending = 0
    maximum_dispatch_lag = 0.0
    phase_start = time.perf_counter()
    with ThreadPoolExecutor(max_workers=24) as executor:
        for due, kind, index in schedule:
            remaining = phase_start + due - time.perf_counter()
            if remaining > 0:
                time.sleep(remaining)
            maximum_dispatch_lag = max(
                maximum_dispatch_lag,
                time.perf_counter() - phase_start - due,
            )
            completed = {future for future in pending if future.done()}
            for future in completed:
                completed_kind, latency = future.result()
                latency_by_kind[completed_kind].append(latency)
            pending.difference_update(completed)
            if len(pending) >= 64:
                raise AssertionError("Registry workload generated an unbounded request backlog.")
            pending.add(executor.submit(request, kind, index))
            maximum_pending = max(maximum_pending, len(pending))

        while pending:
            completed, pending = wait(pending, timeout=10, return_when=FIRST_COMPLETED)
            if not completed:
                raise AssertionError("Registry workload did not drain within ten seconds.")
            for future in completed:
                completed_kind, latency = future.result()
                latency_by_kind[completed_kind].append(latency)

    if maximum_dispatch_lag > 1.0:
        raise AssertionError(f"Load generator fell {maximum_dispatch_lag:.3f}s behind schedule.")
    return {
        "lookups": len(latency_by_kind["lookup"]),
        "heartbeats": len(latency_by_kind["heartbeat"]),
        "lookup_p95_ms": round(percentile(latency_by_kind["lookup"], 95) * 1000, 2),
        "lookup_p99_ms": round(percentile(latency_by_kind["lookup"], 99) * 1000, 2),
        "heartbeat_p95_ms": round(percentile(latency_by_kind["heartbeat"], 95) * 1000, 2),
        "heartbeat_p99_ms": round(percentile(latency_by_kind["heartbeat"], 99) * 1000, 2),
        "maximum_pending": maximum_pending,
        "maximum_dispatch_lag_ms": round(maximum_dispatch_lag * 1000, 2),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--rqlited", required=True)
    parser.add_argument("--server", required=True)
    parser.add_argument("--normal-seconds", type=int, default=10)
    parser.add_argument("--burst-seconds", type=int, default=10)
    args = parser.parse_args()
    if not 2 <= args.normal_seconds <= 300 or not 2 <= args.burst_seconds <= 300:
        parser.error("Workload phase duration must be between 2 and 300 seconds.")

    with tempfile.TemporaryDirectory(prefix="baander-registry-load-") as directory:
        cluster = Cluster(directory, args.rqlited, 3)
        apis = []
        try:
            cluster.start()
            endpoints = [(index, port) for index, port in enumerate(cluster.http_ports)]
            for index in range(3):
                apis.append(
                    Api(cluster, args.server, f"load-api-{index}", endpoints,
                        database_connections=8)
                )

            registrations = [registration(f"load-{index:03d}") for index in range(100)]
            for index, item in enumerate(registrations):
                status, body, _ = apis[index % len(apis)].request(
                    "POST", "/api/servers/register", item
                )
                if status != 200 or body.get("data", {}).get("revision") != 1:
                    raise AssertionError(f"Initial registration failed: {item['publicId']}")
            for item in registrations[:10]:
                if apis[0].request("GET", "/api/servers/" + item["publicId"])[0] != 200:
                    raise AssertionError("Registry warm-up lookup failed.")

            stop_sampling = threading.Event()
            peak_rss = [max(process_rss_bytes(api.process) for api in apis)]
            sampling_errors = []

            def sample_memory():
                try:
                    while not stop_sampling.wait(0.05):
                        for api in apis:
                            peak_rss[0] = max(peak_rss[0], process_rss_bytes(api.process))
                except BaseException as failure:
                    sampling_errors.append(failure)
                    stop_sampling.set()

            sampler = threading.Thread(target=sample_memory)
            sampler.start()
            try:
                normal = run_phase(apis, registrations, args.normal_seconds, 20, 100 / 60)
                burst = run_phase(apis, registrations, args.burst_seconds, 100, 500 / 60)
            finally:
                stop_sampling.set()
                sampler.join(timeout=2)
            if sampler.is_alive():
                raise AssertionError("Registry API memory sampler did not stop.")
            if sampling_errors:
                raise AssertionError("Registry API memory sampling failed.") from sampling_errors[0]

            report = {
                "normal": normal,
                "burst": burst,
                "api_peak_rss_mib": round(peak_rss[0] / 1024 / 1024, 2),
            }
            print(json.dumps(report, sort_keys=True))
            normal_latency_failed = (
                normal["lookup_p95_ms"] > 1000
                or normal["lookup_p99_ms"] > 2000
                or normal["heartbeat_p95_ms"] > 1000
                or normal["heartbeat_p99_ms"] > 2000
            )
            if normal_latency_failed:
                raise AssertionError("Normal latency exceeded its release threshold.")
            if burst["lookup_p99_ms"] > 3000 or burst["heartbeat_p99_ms"] > 3000:
                raise AssertionError("Burst latency exceeded its release threshold.")
            if peak_rss[0] > 64 * 1024 * 1024:
                raise AssertionError("Registry API exceeded 64 MiB peak sampled RSS.")
            print("PASS: bounded three-voter registry workload smoke.")
        finally:
            close_resources([*apis, cluster])


if __name__ == "__main__":
    main()
