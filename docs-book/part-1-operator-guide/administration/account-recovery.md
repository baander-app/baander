# Account recovery and sign-in methods

Use these procedures to help a user recover access, verify an address or change sign-in methods. Run console commands on the installation host in the shell where the [`dc` function](../getting-started/installation.md#command-context) is defined. Console access carries full authority; the admin API applies the role checks described in [User management](user-management.md#who-can-manage-users).

## Passkeys (WebAuthn)

Each user registers their own passkeys in the browser through the standard WebAuthn API; operators do not manage passkeys on a user's behalf. Adding a passkey needs a verified email address and the user's current password, plus a TOTP code when TOTP is on, and removing one needs the same password and code. Adding or removing a passkey signs the user out of every other session, paired devices and apps included. An account with TOTP on accepts a passkey sign-in only when the authenticator verified the user, for example with a PIN or fingerprint. Passkey sign-in names no account: the browser offers the passkeys the device holds for this installation.

Browser and authenticator support depend on the client device. Test registration and
sign-in with the clients your installation supports.

Users can register multiple passkeys on the same account and use any of them to authenticate. If a passkey is lost or the device is unavailable, users fall back to password authentication.

## TOTP 2FA

Users enable and disable time-based one-time password (TOTP) two-factor
authentication through the API, using a compatible authenticator app.

The setup endpoint needs a verified email address and the user's current password,
and returns a secret and provisioning URI. The user confirms a code from their
authenticator before TOTP is enabled; disabling TOTP requires the current password and
a current code. Turning TOTP on or off signs the user out of every other session,
paired devices and apps included. Each code works once. Baander stores the secret encrypted under
`AUTH_TOTP_ENCRYPTION_KEY`; see [Authentication](../configuration/account-security.md#totp).

There are no recovery codes. When a user has lost their authenticator, or their
sign-in answers that TOTP is unavailable because the configured key no longer opens
their secret, turn TOTP off for them:

```bash
dc exec web php bin/console app:user:reset-totp alice@baander.app
```

The reset also signs the user out of every session. Afterwards they sign in with their
password and can set TOTP up again. Confirm the request with the user through a
channel you trust first, because the reset removes their second factor. A super
administrator can do the same through the admin API
(`POST /api/admin/users/{publicId}/reset-totp`), but not for their own account. A
password reset leaves TOTP on, unless the account's address was never verified; see
[Resetting a Password](#resetting-a-password). See
[app:user:reset-totp](../commands/app-user-reset-totp.md).

## Resetting a Password

Set a new password for a user who has forgotten theirs:

```bash
dc exec web php bin/console app:user:reset-password alice@baander.app
```

The command prompts for the password, or reads it from stdin with `--password`. It accepts an email address or public ID and does the same as **Reset password** in the admin panel. Both sign the user out of every session. See [app:user:reset-password](../commands/app-user-reset-password.md).

Users who forget their password can reset it themselves once [email is configured](../configuration/environment-variables.md#mail):

1. On the login page, the user selects **Forgot password?** and enters their email address. The page shows the same confirmation whether or not an account uses that address.
2. If an account does, Baander emails it a link to the **Choose a new password** page. The link works once and expires after `PASSWORD_RESET_EXPIRE` minutes (60 by default).
3. The user enters the new password twice. It must be 8 to 255 characters. If the link was already used, has expired, or the account is disabled, the page says the link is invalid or has expired and links to the request page.
4. Baander sets the password, signs the user out of every session, and returns to the login page with a confirmation.

Opening the link proves the user reads that mailbox. If the account's address had never been verified, someone else may have registered it, so the reset also verifies the address and removes the account's TOTP and passkeys.

By default a user can ask for a link 10 times per 15 minutes; after that the page still confirms but no email is sent. See [Password reset](../configuration/account-security.md#password-reset) for the limits and how the link is built. API clients use the same two endpoints as the web pages: `POST /api/auth/password/reset-request` and `POST /api/auth/password/reset`.

## Verifying Email Addresses

Baander sends notification email only to verified addresses. Until its address is verified, an account can only confirm it: it reads its own profile, opens its link, asks for a new one, changes its address and signs out, and the web app shows a verification screen in place of the app. Operator-created accounts start verified. Self-registered accounts start unverified, and Baander emails the address a verification link once [email is configured](../configuration/environment-variables.md#mail):

1. The user opens the link, which leads to the web app's **Verify your email** page. The link confirms the address only in a signed-in session of the account it was sent for. Signed out, the page asks the user to sign in and then returns to the link; signed in to another account, it says so and offers to sign out, and the link stays usable. The page confirms the address, or says the link is invalid or has expired.
2. The link works once and expires after 24 hours. It verifies only the address it was sent to, so after another email change it no longer works.
3. The verification screen offers **Resend verification email**. A new link replaces the earlier one. By default a user can ask 3 times per hour; after that the page still confirms but no email is sent.

On an installation without email, or for a user who cannot receive the link, mark the current address verified yourself:

```bash
dc exec web php bin/console app:user:mark-email-verified alice@baander.app
```

A super administrator can do the same through the admin API (`POST /api/admin/users/{publicId}/verify-email`). See [app:user:mark-email-verified](../commands/app-user-mark-email-verified.md).

See [Email verification](../configuration/account-security.md#email-verification) for the lifetime, the limits and how the link is built.

## Changing an Email Address

Users change their own address in **Settings**. Operators change it in the admin panel or with the CLI:

```bash
dc exec web php bin/console app:user:change-email alice@baander.app alice.new@baander.app
```

The command accepts the current email address or the public ID. The two ways differ:

- **A user's own change** needs their current password, plus a TOTP code when TOTP is on. The new address stays pending, shown in **Settings**, and Baander emails it a verification link. The account keeps its old address, and that address's verification, until the user opens the link while signed in to the account. The request gets the same answer whether or not another account uses the new address; if one does when the link is opened, the change is dropped. When the change takes effect, every other session of the account ends, paired devices and apps included.
- **An operator's change** in the admin panel or with the CLI takes effect at once and counts as verified. It drops a pending change and ends no session. An administrator cannot change their own address through the admin API; they use **Settings** like any user.

When the change takes effect, Baander emails the old address that the account's address changed, and the verification and password reset links sent to the old address stop working. See [app:user:change-email](../commands/app-user-change-email.md).
