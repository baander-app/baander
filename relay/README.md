# Baander registry

The replacement is a C++20 registry core backed exclusively by rqlite's HTTP API.
The PHP service remains in this directory until the replacement server and its
integration checks are coherent. Do not deploy the existing PHP service: its
repository initialization drops registrations, credentials are stored in plaintext,
and separate credentials can claim the same public identity. Existing SQLite data
is left untouched by this implementation; it is never opened or migrated.

The current batch implements validation, parameterized rqlite requests, safe result
mapping, and tests. It does not yet provide the HTTP server, async connection pool,
production TLS configuration, or deployment/backup automation.

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
and matching checksum; the forthcoming server must use it to gate readiness. A transaction claims the public identity
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
The forthcoming HTTP layer must add `Retry-After` to 503 responses. A confirmed
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

These checks establish local core behavior. They do not qualify five-voter
consistency under partitions, real TLS/network behavior, bounded resource usage,
regional latency, failover/backup recovery, fuzzing, or the 24-hour soak. Those
remain release gates in the root roadmap.

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
