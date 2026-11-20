---
title: Credential changes fence token issuance with a credential epoch
date: 2026-11-20
category: architecture-patterns
module: Auth token issuance (login, authorization code, device code, refresh)
problem_type: architecture_pattern
component: authentication
related_components: [database]
severity: high
applies_when:
  - Adding an operation that ends or replaces a user's credential (password, TOTP, passkeys, a reset)
  - Adding a grant or flow that verifies a credential in one request and issues tokens in a later one
  - Adding a single-use credential check (a TOTP step, a one-time code) to a path that issues tokens
  - Reviewing code that revokes a user's sessions and assumes nothing can mint a new one afterwards
symptoms:
  - "Revoking every token on a password change left a window: a login whose password check passed just before the change committed still received a session"
  - "An unredeemed authorization code, an approved device code or a refresh racing the change could mint tokens after the revocation"
  - "The operator TOTP reset revoked sessions but did not advance the epoch, which a code review caught after the fence shipped"
root_cause: concurrency
tags: [credential-epoch, token-issuance, password-change, totp, refresh-token, row-lock, for-no-key-update, revocation-race]
---

# Credential changes fence token issuance with a credential epoch

## Context

A password change used to revoke the user's access and refresh tokens, and that was all. Revocation removes the tokens that exist when it runs. It does not stop tokens that are minted afterwards from credentials verified before the change: a login whose password check passed but whose tokens were not yet stored, an authorization code or an approved device code not yet exchanged, and a refresh already in flight. Each of these could issue a working session after the user changed their password. The security review on 2026-11-19 found the gap, and the hardening plan `docs/plans/2026-11-19-2119-fix-auth-security-hardening-plan.md` fixed it (commits `63d24010e`, `75159b020`, `168747cc3` on `master`).

## Guidance

Give each user a credential epoch, an integer that every credential change advances, and make every token issuance prove it holds the current one.

1. **Capture the epoch where the credential is verified, and carry it.** The login carries it on `IssueTokenCommand` (`src/Auth/Application/Command/OAuth/IssueTokenCommand.php:20`). Passkey login takes it from the owner it authenticated (`src/Auth/Application/CommandHandler/Passkey/AuthenticatePasskeyHandler.php:102`). A consent decision and a device approval take it from the access token they were sent with and store it on the code (`CreateAuthorizationCodeCommand.php:20`, `ApproveDeviceCodeHandler.php:47`). Refresh tokens carry the epoch of their chain.
2. **Compare it inside the issuing transaction, under a row lock.** `TokenPairIssuer::issue` locks the user row and refuses a stale epoch before writing anything (`src/Auth/Application/Service/TokenPairIssuer.php:131`). `RefreshTokenHandler` does the same before it rotates (`RefreshTokenHandler.php:200`, `:402`). The lock is `SELECT credential_epoch ... FOR NO KEY UPDATE` (`src/Auth/Infrastructure/Doctrine/UserRepository.php:209`), and it throws outside a transaction, where it would fence nothing (`:197`).
3. **Advance the epoch in the same transaction as the credential change.** `PasswordChanger::change` and `ResetUserTotpHandler` call `UserRepository::advanceCredentialEpoch` (`PasswordChanger.php:73`, `ResetUserTotpHandler.php:55`). A password change that keeps the requesting session moves that session's access token and refresh chain to the new epoch (`PasswordChanger.php:79-80`); every other session is revoked and could not be refreshed anyway.
4. **Spend single-use checks inside the same transaction, after the fence.** Password login with TOTP spends its step through `TokenPairIssuer`'s `persistWithTokens` callback (`IssueTokenHandler.php:62`, `:72`). The callback runs after the epoch check (`TokenPairIssuer.php:131-135`), so a login refused for a stale epoch does not burn the code, and a step another request spent first rolls the tokens back.

Why this lock mode: `FOR SHARE` deadlocks, because two logins each holding a share lock both try to upgrade it to spend the TOTP step with an UPDATE of the same row. `FOR UPDATE` would block every insert whose foreign key references the user. `FOR NO KEY UPDATE` serializes issuance against credential changes and leaves those inserts alone. A row lock that waited for a committed update returns the updated row, so the epoch it reads is the one the change wrote (comment at `UserRepository.php:202-207`).

## Why This Matters

Revocation alone looks complete in review and in every test that runs requests one after another. The failure shows only when a request verifies the old credential, the change commits, and the request then stores its tokens. The epoch closes every such path at one point, the issuing transaction, instead of asking each flow to notice the change.

The fence holds only if every credential change advances the epoch. The operator TOTP reset first shipped revoking sessions without advancing it, so a code or refresh chain from before the reset could still issue tokens; `ce-code-review` caught it and `168747cc3` fixed it. No check enforces the pairing, because not every revocation is a credential change: `DisableUserHandler` revokes sessions without advancing the epoch, and issuance refuses a disabled user by its state.

## When to Apply

- A new way to change, reset or remove a credential must call `advanceCredentialEpoch` in its transaction.
- A new grant or two-step flow must capture the epoch where it verifies the credential, store it with any pending state, and pass it to `TokenPairIssuer`.
- A new single-use check on a token-issuing path belongs in `persistWithTokens`, not before the call to `issue`.

## Examples

`tests/Integration/PasswordChangeSessionRevocationTest.php` proves the races against PostgreSQL: a session from an earlier epoch is refused (`testASessionIssuedUnderAnEarlierCredentialEpochIsRefused`), a login whose password check passed before a change commits gets no session (`testALoginWhosePasswordCheckPassedBeforeAChangeCommitsGetsNoSession`), and a refresh racing a change, or an epoch change that revokes nothing, leaves no usable token (`testARefreshRacingAPasswordChangeLeavesNoUsableToken`, `testARefreshRacingAnEpochChangeThatRevokesNothingIsRefused`). An issuance that times out waiting for the row lock answers 503 with `Retry-After` (`testALoginWhoseIssuanceTimesOutOnTheAccountsRowAnswers503WithRetryAfter`). `tests/Integration/TotpLoginReplayTest.php` covers the single-use TOTP step.

## Related

- [Concurrent imports find or create catalog identities under sorted advisory locks](concurrent-imports-find-or-create-identities-under-sorted-advisory-locks.md): the same `FOR NO KEY UPDATE` reasoning for parent rows.
- `CONCEPTS.md` defines first-party clients, delegated tokens and step-up re-authentication.
