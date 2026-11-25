# app:oauth:client:replace-keys

Replace the public keys of a confidential OAuth client that authenticates with `private_key_jwt`. Use it when the client's signing key changes, or to move the client between an inline key set and a `jwks_uri`. The command does what `POST /api/admin/oauth/clients/{clientId}/keys` does in the admin API.

## Quick start

The Make examples below target the checkout's development preview. For an
installation, use the `dc` function from
[Installation](../getting-started/installation.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec web php bin/console …`.

Point the client at the key set it publishes:

```bash
make exec cmd="php bin/console app:oauth:client:replace-keys V1StGXR8_Z5jdHi6B-myT --jwks-uri https://scrobble.baander.app/.well-known/jwks.json"
```

Install a new inline key set from a file inside the container:

```bash
make exec cmd="php bin/console app:oauth:client:replace-keys V1StGXR8_Z5jdHi6B-myT --jwks-file /tmp/scrobble-jwks.json"
```

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `client-id` | Yes | The client's `client_id`, as [app:oauth:client:list](app-oauth-client-list.md) shows it |

## Options

Give exactly one of `--jwks-file` and `--jwks-uri`.

| Option | Description |
|--------|-------------|
| `--jwks-file` | Path to a file holding the client's public keys as a JWK Set, a JSON object with a `keys` list |
| `--jwks-uri` | An `https` URI on port 443 where the client publishes its JWK Set |
| `--json` | Print only the client, as the admin API's `data` payload, in JSON |

## Details

The new keys replace the old ones at once: an assertion signed with a key that is no longer registered stops authenticating. Access and refresh tokens the client already holds stay valid. The new key source may differ in kind from the old one, so a client can move from an inline set to a `jwks_uri` or back. Its authentication method stays `private_key_jwt`.

An inline set holds at most 10 keys in at most 64 KB. Every key must be a public asymmetric key; a set that contains private key material or an `oct` key is refused. When the set holds more than one key, each key needs its own `kid`, because an assertion's `kid` header selects the key that checks it.

A `jwks_uri` must be `https` on the default port and carry no user name or password. Baander fetches the set from it when the client authenticates. A replacement makes any set already fetched from the old source unusable.

The command prints the client, including its authentication method and `jwks_uri`. With `--json` it prints only the client as the API's `data` object, the same resource `POST /api/admin/oauth/clients/{clientId}/keys` returns: `clientId`, `name`, `type`, `authMethod`, `jwksUri`, `redirectUris`, `revoked`, `createdAt` and `updatedAt`. An inline key set is not printed.

The command refuses:

- clients that authenticate with a secret, and device and public clients, which have no keys;
- revoked clients;
- the first-party client and personal access clients, which administrators do not manage.

A client that authenticates with a secret cannot switch to keys. Register a new client with [app:oauth:client:create](app-oauth-client-create.md) and `--auth-method private_key_jwt` instead.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Keys replaced and the client printed |
| 1 | The client ID is malformed or unknown, the client has no keys, is revoked or is not administered, or another error occurred; the message says why |
| 2 | Neither or both key options were given, the file cannot be read or holds no JSON object, or the keys were rejected, as the API rejects them with 422; nothing was changed |

## Tips

- To retire a client entirely, including its issued tokens, use [app:oauth:client:revoke](app-oauth-client-revoke.md).
- Super administrators can replace keys in the admin web UI as well; both paths apply the same rules.
