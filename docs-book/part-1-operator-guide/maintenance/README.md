# Maintenance and Security

Before upgrading or changing credentials, identify the data and running processes
the change affects. Start with backup and recovery for a planned maintenance
window, or incident response when credentials may already be exposed.

| Guide | Scope |
|-------|-------|
| [Backup and Recovery](backup-and-recovery.md) | Choosing the recovery set, capturing a consistent maintenance checkpoint, verifying restores in isolation, and handling partial failures |
| [Upgrading](upgrading.md) | Update code, images and database schema; understand rollback limits |
| [Web Server Restarts](web-server-restarts.md) | Drain requests and observe shutdown deadlines |
| [Debian 13 Upgrade](debian-13-upgrade.md) | Additional database and runtime checks when changing the base distribution |
| [Security Guide](security.md) | Choose a security task and check host, network and TLS protections |
| [Credential Rotation](credential-rotation.md) | Rotate `APP_SECRET`, OAuth keys, Redis passwords, VAPID keys and database credentials |
| [Incident Response](incident-response.md) | Contain a breach and assess recovery |
| [Outbound Provider Security](outbound-provider-security.md) | Understand restrictions and failure behavior for external provider requests |
