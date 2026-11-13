# Maintenance and Security

Protect application state before making changes, update code and database schemas safely, rotate cryptographic secrets and credentials, and harden the host installation.

| Guide | Scope |
|-------|-------|
| [Backup and Recovery](backup-and-recovery.md) | Choosing the recovery set, capturing a consistent maintenance checkpoint, verifying restores in isolation, and handling partial failures |
| [Upgrading](upgrading.md) | Standard update procedure, draining queued work, running database migrations, rebuilding images, web server drain deadlines, and rollback |
| [Security Guide](security.md) | Rotating `APP_SECRET`, OAuth keys, Redis passwords, VAPID keys, and database credentials; breach recovery; and network/TLS hardening |
