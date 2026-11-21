---
title: Password Rehash and Emergency OAuth Rotation - Plan
type: feat
date: 2026-11-21
---

# Password Rehash and Emergency OAuth Rotation - Plan

## Summary

Upgrade obsolete password hashes during successful password login. Verify the existing offline OAuth signing-key cutover through the login, access, and refresh endpoints, and document how to recover when the old private key may have been exposed.

## Problem Frame

`PasswordHasherInterface::needsRehash()` has no login caller. Users who keep the same password retain the algorithm and cost settings that were current when they set it. The existing rotation tests cover the key bundle, grant deletion, and container wiring independently. They leave the outcome across a real cutover unverified, and the failure table in `app-auth-rotate-secrets.md` allows restoring old key material without distinguishing a suspected leak.

## Product Contract

### Requirements

#### Password login

- R1. For each successful password login, apply the configured hash policy to the verified password and replace the stored hash if it needs an upgrade.
- R2. A failed login leaves the hash intact. Hash upgrades preserve the credential epoch, existing sessions, and outstanding password reset links. A concurrent password change takes precedence over an upgrade.

#### Emergency rotation

- R3. After the supported offline cutover, previously issued access and refresh tokens and outstanding authorization and device codes cannot restore a session. Users can sign in again with existing credentials under the replacement signing key.
- R4. The operator runbook explains how to recover after a failed cutover when the previous private key may have been exposed.

### Acceptance Examples

- AE1. Covers R1–R2. A valid login upgrades an obsolete hash. The next valid login keeps the resulting hash. A wrong password or a rejected second factor leaves it untouched.
- AE2. Covers R2. A failed token issuance rolls the upgrade back. A password change committed after verification still rejects the stale login and retains the changed password.
- AE3. Covers R3. A user can access and refresh before the offline cutover. After the cutover the earlier session is refused, JWKS advertises the replacement key, and a fresh login can access the API.

## Key Technical Decisions

- KTD1. Prepare the replacement hash after password and TOTP matching in `PasswordAuthenticator`, before the issuance transaction. Carry only the verified hash and replacement hash through the password login DTO and synchronous `IssueTokenCommand`. This avoids running Argon2id while holding a database row lock. Governs R1–R2.
- KTD2. Apply the upgrade through the existing issuance callback after the credential epoch check and any single-use TOTP spend. Use a parameterized update of only `users.password` with the user ID and previously verified hash as predicates. The user row lock fences credential changes and the hash predicate lets a concurrent upgrade win without invalidating the second login. The callback shares the token transaction. Preserve reset links because the underlying password and email are the same. See `docs/solutions/architecture-patterns/credential-changes-fence-token-issuance-with-a-credential-epoch.md` and [PostgreSQL transaction isolation](https://www.postgresql.org/docs/18/transaction-iso.html#XACT-READ-COMMITTED). Governs R1–R2.
- KTD3. Use `app:auth:rotate-secrets prepare`, offline `invalidate`, and a restart with both new key paths. `DoctrineOAuthTokenInvalidator` already deletes all five grant and metadata tables and clears the token cache. Exercise that sequence on disposable services. Governs R3–R4.

## Implementation Units

### U1. Upgrade obsolete hashes at password login

- Covers R1–R2 and KTD1–KTD2.
- Extend `PasswordAuthenticator`, `VerifiedPasswordLogin`, `LoginController`, `IssueTokenCommand`, `IssueTokenHandler`, and the Auth user repository. Keep the managed Doctrine entity consistent where the new database write can affect subsequent reads.
- Verify upgrade, repeat login, rejection, rollback on signing failure, and the credential-change fence using the existing Auth fixtures.

### U2. Verify and document the signing-key cutover

- Covers R3–R4 and KTD3.
- Add a production-firewall acceptance case for the operator command, session invalidation, JWKS key change, and fresh login. Keep the existing all-grants invalidation tests in the verification run.
- Update `docs-book/part-1-operator-guide/commands/app-auth-rotate-secrets.md` and the maintenance guide where they discuss recovery from a failed cutover.

## Verification

- Run affected Auth unit, functional, and PostgreSQL/Redis integration tests. Record counts and applicable static checks in `ROADMAP.md`.
- The cutover test must use its own database because the invalidation command deletes all grants.
