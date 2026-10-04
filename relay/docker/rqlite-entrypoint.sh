#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
set -eu
fail() { echo "Registry voter startup rejected: $1" >&2; exit 64; }

for name in RQLITE_NODE_ID RQLITE_PRIVATE_BIND_IP RQLITE_HTTP_ADVERTISE RQLITE_RAFT_ADVERTISE; do
    eval "value=\${$name-}"
    [ -n "$value" ] || fail "required configuration $name is missing."
done
case "$RQLITE_NODE_ID" in *[!a-zA-Z0-9_-]*|'') fail "node identity is invalid." ;; esac
private_ip=$RQLITE_PRIVATE_BIND_IP
old_ifs=$IFS
IFS=.
set -- $private_ip
IFS=$old_ifs
[ "$#" = 4 ] || fail "private binding must be an IPv4 address."
for part do
    case "$part" in *[!0-9]*|'') fail "private binding must be an IPv4 address." ;; esac
    [ "$part" -le 255 ] || fail "private binding must be an IPv4 address."
done
case "$private_ip" in
    10.*|192.168.*|172.16.*|172.17.*|172.18.*|172.19.*|172.2[0-9].*|172.30.*|172.31.*) ;;
    *) fail "database ports require an RFC1918 private interface." ;;
esac

data=${RQLITE_DATA_DIR:-/var/lib/rqlite}
secrets=${RQLITE_SECRETS_DIR:-/run/secrets/rqlite}
mode=${RQLITE_START_MODE:-restart}
for file in ca.crt node.crt node.key auth.json; do
    [ -r "$secrets/$file" ] && [ -s "$secrets/$file" ] || fail "required TLS/auth file is unavailable."
done
[ -d "$data" ] && [ -w "$data" ] || fail "data directory must exist and be writable."
[ ! -e "$data/raft/peers.json" ] || fail "manual quorum recovery requires a separate reviewed procedure."
case "$mode" in
    bootstrap|join-new)
        [ -z "$(find "$data" -mindepth 1 -maxdepth 1 -print -quit)" ] || fail "new-node mode requires an empty data directory."
        [ -n "${RQLITE_JOIN_ADDRESSES:-}" ] || fail "new-node mode requires explicit peer inventory."
        ;;
    restart)
        [ -s "$data/raft.db" ] && [ -r "$data/.registry-node-id" ] || fail "restart requires existing voter state; empty data is never initialized automatically."
        [ "$(cat "$data/.registry-node-id")" = "$RQLITE_NODE_ID" ] || fail "data belongs to a different voter identity."
        ;;
    *) fail "mode must be bootstrap, join-new or restart." ;;
esac
if [ "$mode" = join-new ]; then
    peers=
    IFS=,
    for peer in $RQLITE_JOIN_ADDRESSES; do
        if [ "$peer" != "$RQLITE_RAFT_ADVERTISE" ]; then
            peers=${peers:+$peers,}$peer
        fi
    done
    IFS=$old_ifs
    [ -n "$peers" ] || fail "join-new requires a peer other than this node."
    RQLITE_JOIN_ADDRESSES=$peers
fi
set -- -node-id "$RQLITE_NODE_ID" \
    -http-addr 0.0.0.0:4001 -http-adv-addr "$RQLITE_HTTP_ADVERTISE" \
    -raft-addr 0.0.0.0:4002 -raft-adv-addr "$RQLITE_RAFT_ADVERTISE" \
    -http-cert "$secrets/node.crt" -http-key "$secrets/node.key" \
    -http-ca-cert "$secrets/ca.crt" -http-verify-client \
    -node-cert "$secrets/node.crt" -node-key "$secrets/node.key" \
    -node-ca-cert "$secrets/ca.crt" -node-verify-client \
    -node-verify-server-name raft.registry.baander.app -auth "$secrets/auth.json"
case "$mode" in
    restart)
        # In pinned rqlite createCluster(), this rejects missing peer configuration.
        # With existing peers and no join/discovery, persisted voter suffrage is retained.
        # The option is not a membership change and must not accompany join/discovery.
        set -- "$@" -raft-non-voter
        ;;
    bootstrap)
        [ "${RQLITE_EXPECTED_VOTERS:-5}" = 5 ] || fail "this deployment requires five initial voters."
        set -- "$@" -bootstrap-expect 5
        ;;
esac
case "$mode" in
    bootstrap|join-new)
        set -- "$@" -join "$RQLITE_JOIN_ADDRESSES" -join-as registry-cluster \
            -join-attempts 60 -join-interval 1s
        umask 077
        printf '%s\n' "$RQLITE_NODE_ID" > "$data/.registry-node-id"
        ;;
esac
exec /usr/local/bin/rqlited "$@" "$data"
