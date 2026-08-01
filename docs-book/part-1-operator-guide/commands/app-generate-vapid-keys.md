# app:generate-vapid-keys

Generate a new VAPID key pair for Web Push notifications. These keys are required for sending push notifications to browsers.

## Quick start

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

Copy the two keys into your `.env` file (or wherever environment variables are configured). The public key can also be shared with your frontend application.

## Important

Generating new keys **invalidates all existing push subscriptions**. Browsers that were previously subscribed will need to re-subscribe. Only regenerate keys if you're setting up push notifications for the first time or if you need to rotate compromised keys.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Keys generated successfully |

## Tips

- You only need to run this once during initial setup.
- Keep the private key secret — never commit it to version control.
- Store both keys alongside your other environment secrets.
