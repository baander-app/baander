# app:generate-vapid-keys

Generate a new VAPID key pair for Web Push notifications. These keys are required for sending push notifications to browsers.

## Quick start

The Make examples below target the checkout's development preview. For an
installed instance, use the `dc` function from
[Getting started](../getting-started.md#command-context) and replace
`make exec cmd="php bin/console …"` with `dc exec app php bin/console …`.

```bash
make exec cmd="php bin/console app:generate-vapid-keys"
```

Output:

```
================
VAPID Keys Generated
================
Add these to your .env file:

VAPID_PUBLIC_KEY=BIj3...
VAPID_PRIVATE_KEY=MIGT...

 ! [NOTE] Rotating keys will invalidate all existing push subscriptions.
```

## Details

VAPID (Voluntary Application Server Identification) keys let your server authenticate itself with push services. You need these before web push notifications work.

Provision both keys through the instance's private environment configuration.
The public key can be shared with the frontend; keep the private key secret.
Follow [Notifications](../notifications.md) when applying configuration to web
and workers. Generating keys only prints a pair; it changes no active
configuration or subscription.

## Important

Replacing the configured VAPID pair prevents delivery to subscriptions tied to
the old public key. Those browsers need to subscribe again with the new key.
Generate a pair for initial setup or to replace compromised keys; merely running
this command does not install it.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Keys generated successfully |

## Tips

- You only need to run this once during initial setup.
- Keep the private key secret — never commit it to version control.
- Store both keys alongside your other environment secrets.
