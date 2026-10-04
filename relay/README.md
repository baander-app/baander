# Baander registry

The replacement is a C++20 registry core backed exclusively by rqlite's HTTP API.
The PHP service remains in this directory until the replacement server and its
integration checks are coherent. Do not deploy the existing PHP service: its
repository initialization drops registrations, credentials are stored in plaintext,
and separate credentials can claim the same public identity. Existing SQLite data
is left untouched by this implementation; it is never opened or migrated.

The local implementation includes validation, parameterized rqlite requests, a
bounded asynchronous mTLS connection pool, and a TLS HTTP server. Regional
infrastructure, deployment and backup automation remain separate acceptance gates.

## Contract

Registration retains the `publicId`, `url`, `name`, `version`, and `apiKey` fields.
No new request identity field is required. `publicId` is 1–128 ASCII letters,
digits, underscores or hyphens; `name` is 1–128 Unicode code points, `url` at most
2048 bytes, and `version` at most 64 bytes (default `0.0.0` when omitted). The URL must be an
absolute HTTPS URL with a hostname and without user information or a fragment.
Credentials must encode 32 client-generated random bytes as 64 lowercase hex
characters. Validation cannot prove the client's randomness: clients must use a
cryptographic random generator. Only the SHA-256 digest produced by OpenSSL is
persisted, and neither the credential nor digest appears in public responses.

The initial schema has `schema_migrations` (version and SHA-256 checksum) and
`registries` tables. The schema-result validator requires the supported version
and matching checksum before the server becomes ready. A transaction claims the public identity
exclusively, updates only a registration owned by the submitted credential, and
selects its committed revision before responding. A credential may own several
public identities. Identity reservations remain after the server goes offline.
Every successful heartbeat, including a retry, advances the revision; `updated_ms`
and `last_seen_ms` never move backwards. Retries with the same credential never
duplicate a public identity or change its owner. The
protocol has no client sequence number, so it does not order concurrent stale
metadata updates or deduplicate heartbeat revisions. That needs a separately
specified future protocol, not an undocumented required request field.

Lookup explicitly uses `level=linearizable`. Registration uses the transactional
unified endpoint with the same consistency parameter, never queued writes. Every
HTTP status, result count, statement error and selected-column type is checked.
Quorum failures and malformed results produce 503, not a stale result or 404.
The HTTP layer adds `Retry-After: 1` to 503 and 429 responses. A confirmed
empty or offline lookup produces 404 without disclosing a stale URL; a wrong-owner
registration produces 403. Lookup considers a server offline after ten minutes.
Public response envelopes remain `data`, with registration status and lookup
metadata; revision, update and heartbeat milliseconds make the new lifecycle explicit.

## Local checks

Dependencies are fetched from versioned archives and verified with SHA-256 in
CMake. OpenSSL 3.5.3 must be supplied by the build environment; CMake verifies its
exact reported version. Build outputs belong outside the checkout.

```sh
cmake -S relay -B /tmp/baander-registry-build -G Ninja -DCMAKE_BUILD_TYPE=Debug
cmake --build /tmp/baander-registry-build --parallel 2
ctest --test-dir /tmp/baander-registry-build --output-on-failure
```

The loopback rqlite contract test uses a disposable data directory and never
connects to configured registry hosts. Supply a verified rqlited 10.5.1 binary.
It starts one local node, exercises the actual SQL and C++ response decoder, and
always terminates the process and removes its temporary data.

```sh
python3 relay/tests/run_rqlite_contract.py \
  --rqlited /path/to/verified/rqlited \
  --fixture /tmp/baander-registry-build/registry_contract_fixture
```

The transport test uses a disposable CA, authenticated TLS upstream and client
certificate. It checks SNI and hostname verification, deadlines, pool saturation,
reconnection within the same pool, connection reuse, redirects and invalid JSON.
The API test uses native rqlite with authentication and mTLS, and verifies the
public contract, concurrent claims, readiness, limits, outage and shutdown.

```sh
PYTHONDONTWRITEBYTECODE=1 python3 relay/tests/run_transport_contract.py \
  --fixture /tmp/baander-registry-build/registry_transport_fixture
PYTHONDONTWRITEBYTECODE=1 python3 relay/tests/run_http_contract.py \
  --rqlited /path/to/verified/rqlited \
  --server /tmp/baander-registry-build/baander-registry
```

These checks establish local behavior and TLS interoperability. They do not
qualify three/five-voter partitions, whole-host capacity, regional latency,
failover/backup recovery, fuzzing or the 24-hour soak. Those remain release gates
in the root roadmap; regional inventory and real S3 access are not available yet.

## Server configuration

Run `baander-registry --config /etc/baander-registry/config.json`. The config is
bounded to 64 KiB and JSON nesting to eight levels. The following paths describe
operator-provided files; the repository contains no deployment credentials.

```json
{
  "api": {
    "address": "127.0.0.1", "port": 9502,
    "certificate": "/etc/baander-registry/api.crt",
    "key": "/etc/baander-registry/api.key",
    "maximumSessions": 64, "deadlineMs": 2000, "shutdownGraceMs": 5000,
    "requestsPerSecond": 200, "burst": 200
  },
  "database": {
    "endpoints": [{"url": "https://rqlite.registry.baander.app:4001"}],
    "ca": "/etc/baander-registry/database-ca.crt",
    "certificate": "/etc/baander-registry/database-client.crt",
    "key": "/etc/baander-registry/database-client.key",
    "username": "registry",
    "passwordFile": "/etc/baander-registry/database-password",
    "connections": 8, "deadlineMs": 2000
  }
}
```

The API is TLS only. Routes are `POST /api/servers/register`,
`GET /api/servers/{publicId}`, `GET /health` and `GET /ready`. Each TLS connection
serves one request and closes. Request headers and bodies are limited to 8 KiB,
and registration JSON is limited to eight nesting levels during parsing.
Malformed JSON returns 400, invalid fields 422, wrong ownership 403, oversized
bodies 413, rate limiting 429 and authoritative unavailability 503. Liveness
never depends on rqlite. Readiness reads the schema and registry columns with
linearizable consistency; schema initialization is retried at startup with a
one-second delay, rather than written on every readiness request. A failed schema
read marks the API unready; a later compatible authoritative readiness read
restores it without rewriting the schema.

The server runs one Asio event-loop thread. Session capacity is fixed (default
64, configurable 1–256); excess connections close without queued tasks. Each
session has a deadline covering TLS, request reads, database work and response
writes. The per-instance token bucket has constant state, without a growing
client-IP map. The rqlite pool has 1–16 fixed slots and no waiting queue;
saturation fails with 503. DNS, connection, TLS and response reads share a database
deadline (50–10000 ms). Shutdown stops accepting and closes idle sessions. Admitted requests drain until
`shutdownGraceMs` (default 5000, configurable 50–10000 ms); remaining sockets and
rqlite work are then cancelled. The event loop drains before destroying their owners.

Database connections require an explicit CA, hostname verification and SNI,
client certificate/key, and Basic authentication read from a bounded password
file. Set its filesystem access for the service account only. Optional endpoint
`connectAddress` supplies a static IP without changing the verified hostname.
Redirects are rejected with 503, never followed to an unconfigured host or weaker
read level; a subsequent connection rotates through configured endpoints.
Neither API credentials, database auth headers nor request bodies are logged.
The public server does not proxy rqlite administration routes. Deploy rqlite's
TLS listener and Raft ports on private interfaces with network access restricted
to registry/cluster peers; actual host exposure is a deployment acceptance check.


## Dependencies and licenses

First-party C++ sources and tests are Apache-2.0. The retained PHP application's
historical manifest is separate and will be removed with that implementation.
Third-party license notices are retained in `third_party/`.

| Dependency | Version | Archive SHA-256 | License |
| --- | --- | --- | --- |
| Boost (Asio, Beast, URL and headers) | 1.88.0 | `46d9d2c06637b219270877c9e16155cbd015b6dc84349af064c088e9b5b12f7b` | Boost Software License 1.0 |
| nlohmann/json | 3.12.0 | `42f6e95cad6ec532fd372391373363b62a14af6d771056dbfc86160e6dfff7aa` | MIT |
| GoogleTest (tests only) | 1.17.0 | `65fab701d9829d38cb77c14acdc431d2108bfdbf8979e40eb8ae567edf10b27c` | BSD-3-Clause |
| OpenSSL (environment library) | 3.5.3 | Environment must pin its package/artifact | Apache-2.0 |
| rqlite (external database/test binary) | 10.5.1 | Linux amd64 release: `f0ebf593b573595022947add67cd22e6cbb02c1d2a1ed8c7da45c94093a49b0d` | MIT |

Authoritative contracts: [rqlite HTTP API](https://rqlite.io/docs/api/api/) and
[read consistency](https://rqlite.io/docs/api/read-consistency/).
