# Context Review: Frontend — settings

**Date:** 2026-05-10
**Scope:** settings feature

## Summary
- Features: 1
- Files analyzed: 1
- Errors: 11 (must fix)
- Warnings: 10 (tech debt)
- Info: 8 (informational)

## File Inventory
### settings
pages/:
- pages/SettingsPage.tsx

(No components/, hooks/, stores/, api/, or test directories exist)

## Errors

1. **[pages/SettingsPage.tsx:119] `as any` suppresses type errors on passkey options response** — `postPasskeyOptions` returns a union type wrapping data in nested `{ data: { data: { challengeKey, options } } }`. The code destructures directly as `{ challengeKey, options }` via `as any`, masking a likely runtime undefined. Remediation: Remove `as any`. Destructure correctly (`const { data } = result; const { challengeKey, options } = data`). Check `result.status` for error responses.

2. **[pages/SettingsPage.tsx:141] `as any` on serialized credential removes type safety** — `publicKeyCredentialToJSON` result is cast to `any` to access `.response`. This hides drift between the serialization shape and what `postPasskeyRegister` expects. Remediation: Define explicit return type on `publicKeyCredentialToJSON` matching `RegisterPasskeyRequestResponse`, or construct the request object with typed fields.

3. **[pages/SettingsPage.tsx:52] parseFloat cast to LufsTarget can produce invalid runtime value** — `parseFloat(e.target.value) as LufsTarget` with no runtime validation. Empty value yields NaN, DOM manipulation could inject arbitrary values. Remediation: Add runtime validation: `const parsed = parseFloat(e.target.value); if ([-14, -16, -18, -23].includes(parsed)) setTargetLufs(parsed as LufsTarget)`.

4. **[pages/SettingsPage.tsx:32] Custom toggle instead of shadcn Switch component** — Hand-built button with manual `role="switch"`, `aria-checked`, and CSS transitions. The project has a `Switch` component at `@/shared/components/ui/switch`. Remediation: Replace with `<Switch checked={normalizationEnabled} onCheckedChange={setNormalizationEnabled} aria-label="Volume normalization" />`.

5. **[pages/SettingsPage.tsx:106] PasskeyManagement should be in components/ directory** — 60+ line component with its own state, async logic, and JSX co-located in the page file. Violates feature directory convention (components go in `components/`). Remediation: Move to `ui/web/src/features/settings/components/PasskeyManagement.tsx`.

6. **[pages/SettingsPage.tsx:110] Business logic embedded in component handler** — `handleRegister` is ~40 lines of imperative WebAuthn logic directly in the component. Project rules: "Do not put business logic in components — extract to hooks or stores." Remediation: Extract to `ui/web/src/features/settings/hooks/use-passkey-registration.ts`.

7. **[pages/SettingsPage.tsx:122] User-abort of WebAuthn ceremony shown as error** — `navigator.credentials.create` throws `DOMException` with `name: 'NotAllowedError'` when user cancels. The catch block displays raw error text. Remediation: Check `err instanceof DOMException && err.name === 'NotAllowedError'` and silently return or show non-error feedback.

8. **[pages/SettingsPage.tsx:139] Silent no-op when credential is null** — If `navigator.credentials.create` returns null, code silently does nothing — no error, no feedback. Remediation: Add else branch: `setError('Passkey creation was cancelled. Please try again.')`.

9. **[pages/SettingsPage.tsx:5] No test file for SettingsPage** — Zero test coverage for the main page component. Renders three sections, reads from store, exposes toggle and select interactions. Remediation: Create `__tests__/SettingsPage.test.tsx` covering section rendering, toggle interaction, LUFS select, and EQ link.

10. **[pages/SettingsPage.tsx:106] No test file for PasskeyManagement** — Most complex component with WebAuthn flow, API calls, loading/error state — entirely untested. Remediation: Create `__tests__/PasskeyManagement.test.tsx` covering registration flow, error paths, loading state.

11. **[pages/SettingsPage.tsx:170-199] No tests for WebAuthn utility functions** — `base64ToArrayBuffer`, `publicKeyCredentialToJSON`, `arrayBufferToBase64` are safety-critical pure functions with zero tests. Remediation: Extract to `utils/webauthn-utils.ts` (export them), create `__tests__/webauthn-utils.test.ts` with round-trip and edge case tests.

## Warnings

1. **[pages/SettingsPage.tsx:170] Utility functions co-located in page file** — `base64ToArrayBuffer`, `publicKeyCredentialToJSON`, `arrayBufferToBase64` are non-React helpers at the bottom of a page component. Undiscoverable and un-reusable. Remediation: Move to `ui/web/src/features/settings/utils/webauthn.ts` or `ui/web/src/shared/lib/webauthn.ts`.

2. **[pages/SettingsPage.tsx:50] Native `<select>` instead of shadcn Select** — Inconsistent cross-browser styling, lacks accessibility of the project's Select component. Remediation: Install shadcn Select (`npx shadcn@latest add select`) and replace.

3. **[pages/SettingsPage.tsx:72] `<a>` for client-side navigation** — Full page reload on "Open EQ" link. Remediation: Use router `<Link to="/equalizer">`.

4. **[pages/SettingsPage.tsx:114] Dynamic import inside event handler** — `await import(...)` inside `handleRegister` adds latency on first click and is inconsistent with static imports used elsewhere. Remediation: Convert to static import at file top (or in the extracted hook).

5. **[pages/SettingsPage.tsx:130] excludeCredentials mapping assumes unchecked object shape** — Manual type annotation `{ id: string; type: string }` with no enforcement against server response shape. Drops `transports` field. Remediation: Define proper interface and preserve all WebAuthn fields.

6. **[pages/SettingsPage.tsx:142] Missing `name` field in passkey registration payload** — Backend supports `name` with default `'Passkey'`, but frontend never passes it. Users cannot name passkeys. Remediation: Pass default name explicitly or add a name input.

7. **[pages/SettingsPage.tsx:106] No passkey list/delete UI despite backend support** — Backend has `DELETE /api/auth/passkey/{publicId}` and generated client includes `deletePasskeyDelete()`. Users cannot see or remove registered passkeys. Remediation: Add passkey list view with delete capability.

8. **[pages/SettingsPage.tsx:148] State updates after unmount** — If component unmounts during registration, `finally` block calls `setLoading(false)` and catch calls `setError(...)` on unmounted component. Remediation: Use AbortController or mounted ref to skip updates after unmount.

9. **[pages/SettingsPage.tsx:32] Toggle accessibility untested** — `role="switch"` and `aria-checked` behavior not verified. Remediation: Test that clicking toggles `aria-checked` and calls `setNormalizationEnabled`.

10. **[pages/SettingsPage.tsx:110] WebAuthn error paths untested** — Multiple failure modes (network error, user cancel, server rejection) with no coverage. Remediation: Test each error path separately in `PasskeyManagement.test.tsx`.

## Info

1. **[pages/SettingsPage.tsx:5] Settings reads from equalizer store** — Cross-feature dependency: `useEqStore` from equalizer. If that store's shape changes, settings breaks silently. Remediation: Consider a shared audio-settings store or document the dependency explicitly.

2. **[pages/SettingsPage.tsx:20] Repeated card pattern** — `rounded-lg bg-card p-4` divs repeated 5 times. Project has `Card` component available. Remediation: Use `<Card>`, `<CardHeader>`, `<CardContent>` from shadcn.

3. **[pages/SettingsPage.tsx:77] Audio normalization settings are client-only** — Stored in localStorage via Zustand persist. No backend sync. Lost on device switch or storage clear. Backend has `UserPreference` context but no audio endpoint. Remediation: Add backend endpoint or document as intentionally client-only.

4. **[pages/SettingsPage.tsx:114] Dynamic import failure shows misleading message** — Chunk load failure shows "Failed to register passkey" instead of "Please reload the page." Remediation: Minor — check for `ChunkLoadError` specifically.

5. **[pages/SettingsPage.tsx:147] Error message loses context for non-Error values** — Axios structured errors (`response.data.message`) not extracted. Remediation: Check `AxiosError` for richer messages.

6. **[pages/SettingsPage.tsx:170] Utility functions not exported** — Cannot be imported for testing. Remediation: Extract to separate file with exports.

7. **[pages/SettingsPage.tsx:139] Null credential behavior** — WebAuthn cancellation results in silent no-op with no user feedback. Remediation: Consider showing a message.

8. **[pages/SettingsPage.tsx:106] No success feedback after registration** — Button reverts to "Register Passkey" with no confirmation. Remediation: Add success state or confirmation message.

## Agent Findings

### Component Architecture
- Custom toggle should use shadcn Switch (error)
- PasskeyManagement should be extracted to components/ (error)
- Business logic should be in a hook, not component handler (error)
- Utility functions should be in separate file (warning)
- Native select should use shadcn Select (warning)
- `<a>` should use router `<Link>` (warning)
- Cross-feature store dependency (info)
- Repeated card pattern (info)
- Dynamic import in handler (info)

### TS/React Correctness
- `parseFloat as LufsTarget` without validation (error)
- `as any` on postPasskeyOptions masks response shape mismatch (error)
- `as any` on serialized credential (warning)
- User-abort shown as error (error)
- Silent null credential (error)
- State updates after unmount (warning)
- excludeCredentials unchecked shape (warning)

### API Integration Completeness
- Response type mismatch — nested data not unwrapped (error, merged with correctness finding)
- Missing `name` field in registration payload (warning)
- No passkey list/delete UI (warning)
- Dynamic import inconsistent with rest of codebase (warning)
- Audio settings client-only, no backend sync (info)

### Test Verification
- No test files for any component or utility (3 errors)
- Toggle and select interactions untested (2 warnings)
- WebAuthn error paths untested (1 warning)
- Dynamic import untested (warning)
- Utilities not exported for testing (info)
- Null credential and success feedback behavioral gaps (info)
