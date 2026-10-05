# Baander registry

The registry is a C++20 service backed exclusively by rqlite's HTTP API. Its
container deployment path replaces the obsolete PHP service. Existing SQLite
data and legacy volumes are left untouched; they are never opened or migrated.

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

The CI entrypoint builds pinned dependencies in a disposable directory:

```sh
bash scripts/test-registry.sh release
bash scripts/test-registry.sh sanitize
bash scripts/test-registry.sh thread
```

It compiles OpenSSL 3.5.3 into an isolated prefix and checks its source archive
against the official release SHA-256
`c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf`.
The `sanitize` mode instruments first-party targets with ASan/LSan and UBSan.
The separate `thread` mode uses Clang, `RelWithDebInfo` and ThreadSanitizer with
`TSAN_OPTIONS=halt_on_error=1:exitcode=66`. CI installs `clang-19` and its matching
`libclang-rt-19-dev` runtime. It runs the units, SQL, TLS transport,
HTTP and three-voter contracts, including a positive control that must report an
actual data race and exit 66. The probe repeats volatile writes so its conflicting
accesses are retained and a tiny race window does not make detection intermittent.
Runtime initialization failures do not pass that control. Neither mode establishes complete third-party instrumentation.

For a manual TSan build, add `-DREGISTRY_THREAD_SANITIZER=ON`,
`-DCMAKE_CXX_COMPILER=clang++` and `-DCMAKE_BUILD_TYPE=RelWithDebInfo` to CMake.
This option is mutually exclusive with `REGISTRY_SANITIZERS`. A runner must allow
the TSan runtime to reserve its address space; failures fail qualification and
must be resolved in the runner environment without suppressions. The pool is
confined to one event-loop thread. The concurrent transport fixture uses producer
threads to post work to that loop; it does not qualify calls directly from
multiple threads or multiple concurrent event-loop runners.
The manual commands below also work with an appropriately pinned environment.
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
qualify whole-host capacity, regional latency, physical failure or backup recovery,
fuzzing or the 24-hour soak. Those remain release gates
in the root roadmap; regional inventory and real S3 access are not available yet.

The cluster harness starts three or five native voters with HTTPS authentication
and Raft mTLS. A loopback TLS forwarder verifies each peer certificate, retains
its identity when forwarding, and partitions the Raft links without pausing
processes or changing host networking. Three-voter runs close cross-group links;
five-voter runs stall established cross-group links and reject new ones. Healing
closes stalled links before unblocking, so buffered RPCs are not replayed.

```sh
PYTHONDONTWRITEBYTECODE=1 python3 relay/tests/run_cluster_contract.py \
  --nodes 3 --rqlited /path/to/verified/rqlited \
  --server /tmp/baander-registry-build/baander-registry
PYTHONDONTWRITEBYTECODE=1 python3 relay/tests/run_cluster_contract.py \
  --nodes 5 --rqlited /path/to/verified/rqlited \
  --server /tmp/baander-registry-build/baander-registry
```

The tests check minority 503 responses, majority progress, leader recovery within
30 seconds, retained acknowledged metadata/revisions, rejoin, abrupt voter crashes
(one of three or two of five), and durable catch-up after process restart.
They then stop a majority including the leader (two of three or three of five)
while the API stays running. Existing and absent lookups, registration and readiness
must return 503 with `Retry-After: 1` and fixed public errors without credentials or
metadata; health stays 200. Restarting the persisted voters must restore quorum and
API readiness within 30 seconds, preserve acknowledged identities and revisions,
and allow a new registration.
Uncertain writes are submitted once and are not retried. The actual lease-loss
response can be either HTTP 200 with an error or HTTP 503; a deterministic TLS
pool test replays the observed 200 error to verify next-request rotation without
relying on that timing window. These are local emulations with TLS termination at
the fixture forwarders, rather than certification of regional network behavior,
physical disk/power loss or capacity.

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
deadline (50–10000 ms). API calls cap that deadline at their remaining budget,
reserving up to 100 ms (at most one quarter of the API deadline) to write a known
failure as 503 with `Retry-After`. Incomplete/slow TLS or headers and disconnected
clients remain transport cutoffs. An HTTP 200 database response containing a
statement/top-level error evicts the connection for the next request; the failed
write is never replayed. Shutdown stops accepting and closes idle sessions. Admitted requests drain until
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
| OpenSSL (environment library; isolated CI source build) | 3.5.3 | `c9489d2abcf943cdc8329a57092331c598a402938054dc3a22218aea8a8ec3bf` | Apache-2.0 |
| rqlite (external database/test binary) | 10.5.1 | Linux amd64 release: `f0ebf593b573595022947add67cd22e6cbb02c1d2a1ed8c7da45c94093a49b0d` | MIT |

Authoritative contracts: [rqlite HTTP API](https://rqlite.io/docs/api/api/) and
[read consistency](https://rqlite.io/docs/api/read-consistency/).

## Container packaging and private inventory

The mandatory container gate builds unique image tags and runs the disposable
qualification, then removes its image tags:

```sh
bash scripts/test-registry-container.sh
```

The image build context is allowlisted: local data, keys, operator configuration
and PHP files are excluded. The API image builds C++20 Release code with static
OpenSSL 3.5.3 and the CMake dependency checksums above. The voter image verifies
the rqlite 10.5.1 Linux amd64 archive. Both use the immutable official Debian base
`sha256:3783cc01769c7b2b1b83a5c5ad96c815348e28ed7da68e2e3687004faa906251`.
The verified amd64 child is
`sha256:f3034a6ec3c1205360777c4aae76234998866ad18806ae62b63a3f84ccad782b`.
Only Linux amd64 is qualified here. Debian security packages are installed from
its package repository; these builds do not claim byte-for-byte reproducibility
of the operating-system package set. Dependency updates require new verified pins
and qualification. First-party code is Apache-2.0; runtime images retain dependency
license notices. The operating-system package licenses remain separate obligations.

```sh
docker build --platform linux/amd64 -f relay/docker/Dockerfile \
  -t baander/registry:0.1.0 relay
docker build --platform linux/amd64 -f relay/docker/rqlite.Dockerfile \
  -t baander/registry-rqlite:10.5.1 relay
PYTHONDONTWRITEBYTECODE=1 python3 relay/tests/run_container_contract.py \
  --api-image baander/registry:0.1.0 --rqlite-image baander/registry-rqlite:10.5.1
```

The container check creates a unique network and uniquely named disposable voter
volumes, generates fixture credentials and certificates, and removes only those
resources. Fixtures are copied into named volumes; HTTPS probes run inside the
test network. It needs no host bind mounts, published API ports or shared
runner/daemon filesystem, and is qualified against an isolated remote TLS Docker
daemon. It checks startup guard failures, five-voter formation, authenticated
TLS registration/lookup, rolling restart with retained revisions, nonroot runtime,
read-only containers, clean API SIGTERM and absence of fixture secrets in logs.
`--guards-only` runs startup rejection checks without forming the cluster.

`docker/registry.example.json` is a complete mounted API configuration;
`docker/inventory.example.env` describes one host's inventory. These are examples,
not deployment credentials. Copy and review them outside this checkout. Region
names and domains are configurable; the current inventory is `de`, `ca`, `sg`,
`au`, `fi`. Keep exactly five voter identities independent of how many hosts are
currently reachable. Additional API-only hosts do not become voters.

The base Compose file runs only the API. The five voter hosts explicitly include
`voter.compose.yml`. Database HTTP and Raft published ports require an RFC1918
host interface address, with no broad/default binding. Use private DNS and a
VPN/private network with firewall rules allowing only API hosts and voter peers.
The rqlite administration API is never proxied by the public registry. TLS is
required even on the private network. Docker bridge NAT and published-port rules
must be included in the operator's firewall review.

```sh
# Validate API-only configuration; it needs no voter variables or data volume.
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/docker-compose.yml config --quiet
# Validate one of the five voter hosts, then build the two images locally.
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/docker-compose.yml -f relay/docker/voter.compose.yml config --quiet
docker compose --env-file /etc/baander-registry/inventory.env \
  -f relay/docker/docker-compose.yml -f relay/docker/voter.compose.yml build
```

The API mounts `api.crt`, `api.key`, `api-ca.crt` (health check trust),
`database-ca.crt`, `database-client.crt`, `database-client.key` and
`database-password` under `/run/secrets/registry`. It binds `0.0.0.0` inside its
container and publishes only port 9502. The health check verifies the API's
configured certificate hostname and CA; it never disables verification. Monitor
`/ready` separately for authoritative database readiness; `/health` is liveness.

Each voter mounts `ca.crt`, `node.crt`, `node.key` and `auth.json` under
`/run/secrets/rqlite`. Voter certificates need their private HTTP hostname and
`raft.registry.baander.app` in their DNS SANs, with both server and client TLS
usage. Raft verifies that shared cluster hostname and the trusted peer CA;
HTTP clients verify the individual endpoint hostname. Issuance of peer certificates
therefore grants cluster trust and must remain restricted. The `registry` auth
account needs `query` and `execute`; `registry-cluster` needs the `join` permission
for joining and bootstrap notifications. Keep administrative accounts separate.
The API password file and
rqlite auth file must agree. Credentials are supplied through mounted files,
not command-line passwords. Private files must be readable by UID/GID 10001,
for example owned by that UID with mode 0600; never make operator keys world-readable.
Do not mount the CA signing key into either runtime.

Voter startup modes are explicit:

- `bootstrap`: a reviewed first creation, requiring an empty directory, an explicit
  peer inventory and exactly five expected voters. All five initial hosts use this
  mode together. It never reduces the count to match currently online hosts.
- `join-new`: an explicitly provisioned empty replacement/new voter joining an
  existing cluster, with no bootstrap option. Review membership removal and node
  identity separately; do not grow the agreed five-voter set accidentally.
- `restart`: the default, requiring existing native `raft.db` and the matching
  recorded voter identity. Empty or wrong-identity directories fail closed. This
  mode neither joins automatically nor starts a competing cluster after quorum loss.

Restart additionally passes rqlite's `-raft-non-voter` startup flag **without** any
join or discovery option. In pinned 10.5.1, `createCluster` rejects a missing peer
configuration on this path; existing persisted membership remains unchanged.
This blocks a partially initialized `raft.db` from accidentally bootstrapping one
voter after an interrupted five-voter creation. The container check reproduces
that failure and verifies all five persisted members remain voters after restart.
Do not combine this restart guard with join/discovery or CDC; dependency upgrades
must reverify these native semantics against the
[pinned rqlite startup implementation](https://github.com/rqlite/rqlite/blob/v10.5.1/cmd/rqlited/main.go).

After successful initial creation/join, set the inventory to `restart` and recreate
that container with its same persistent volume. Leaving `bootstrap`/`join-new`
configured on a populated volume intentionally fails. Any `raft/peers.json` recovery
file is rejected; quorum-loss recovery requires a separate reviewed offline procedure.
No startup path deletes state or silently repairs/reinitializes a volume. Inspect
membership after provisioning and upgrade one voter at a time while quorum remains.

API and voter limits total one CPU and 448 MiB per host (96 MiB API, 352 MiB voter).
These are containment limits, not measured acceptance evidence: whole-host RSS/CPU,
regional latency, physical failure, backups, restore, and soak gates remain pending.
The old `relay_data` volume and local SQLite files are deliberately unreferenced;
they are not opened, converted or reset. Never use `docker compose down -v` on an
operator inventory, and never reuse that legacy volume as native rqlite storage.
External deployment access and an actual S3 destination are not available yet.
