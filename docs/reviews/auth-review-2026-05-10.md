# Context Review: Frontend — auth

**Date:** 2026-05-10
**Scope:** auth feature (auth flow and token management)

## Summary
- Features: 1
- Files analyzed: 6 (auth feature) + 5 shared infrastructure
- Errors: 7 (must fix)
- Warnings: 10 (tech debt)
- Info: 13 (informational)

## File Inventory

### auth

stores/:
- stores/auth-store.ts

components/:
- components/LoginForm.tsx
- components/RegisterForm.tsx
- components/ProtectedRoute.tsx

pages/:
- pages/LoginPage.tsx
- pages/RegisterPage.tsx

### Related shared infrastructure (reviewed for context)
- shared/api-client/axios-instance.ts
- shared/crypto/dpop-key-pair.ts
- shared/crypto/dpop-store.ts
- shared/crypto/dpop-proof.ts
- features/player/services/service-worker-bridge.ts

## Errors (must fix)

- [auth-store.ts:68] **Login response status check always fails** — `customInstance` strips the Axios envelope, returning only the HTTP body `{data: {...}}`. The `response.status` field does not exist at runtime (`undefined !== 200` → `true`), so the error branch always executes. Every successful login throws "Login failed" — tokens are never stored and the user is never authenticated. Sources: Component Architecture, TS/React Correctness, API Integration. Remediation: Remove the `response.status !== 200` check entirely. Axios throws on non-2xx by default; if the promise resolves, login succeeded.

- [auth-store.ts:83] **DPoP nonce extraction from login response headers is broken** — After `customInstance` unwrapping, `response.headers` is `undefined`. The DPoP nonce from the login response is never stored, breaking subsequent DPoP-proof requests. Sources: TS/React Correctness, API Integration. Remediation: Bypass `customInstance` for the login call and use `AXIOS_INSTANCE` directly to get the full `AxiosResponse` with headers, or have the backend include the DPoP nonce in the response body.

- [auth-store.ts:102] **Registration sends empty name — guaranteed 422 validation error** — `register()` sends `{email, password, name: ''}`. The backend `RegisterRequest` has `#[NotBlank]` on `name`, so this always returns HTTP 422 "Name is required." Source: API Integration. Remediation: Add a name field to `RegisterForm` and pass it to `register()`, or make the backend `name` field optional.

- [service-worker-bridge.ts:15] **CryptoKey cannot be cloned by postMessage** — `postMessage` passes a `CryptoKey` object which the structured clone algorithm does not support. This throws `DataCloneError`, silently swallowed by `.catch(() => {})`. The service worker never receives the DPoP private key. Source: TS/React Correctness. Remediation: Export the private key to JWK before sending (`crypto.subtle.exportKey('jwk', privateKey)`) and re-import in the service worker.

- [auth-store.ts:74] **Unsafe type assertions on response data hide runtime failures** — `as string` and `as User` casts on login response data with no runtime validation. If the API shape diverges, the app operates with corrupt state. Sources: Component Architecture, TS/React Correctness. Remediation: Add runtime checks (`if (!response.data.accessToken) throw ...`) or validate against a schema.

- [SettingsPage.tsx:119] **Passkey options response destructuring misses data envelope** — `postPasskeyOptions` returns `{data: {challengeKey, options}}` but the code destructures from `result` directly, so `challengeKey` and `options` are `undefined`. WebAuthn registration fails. Source: API Integration. Remediation: Destructure from `result.data` or `(result as any).data`.

- [auth-store.ts, LoginForm.tsx, RegisterForm.tsx, ProtectedRoute.tsx, LoginPage.tsx, RegisterPage.tsx] **Zero test coverage for entire auth feature** — 6 source files, 0 test files. The auth store (6 actions), login form (3 error shapes, TOTP flow), register form (validation, error paths), ProtectedRoute (auth guard), and both page redirect guards are all untested. Source: Test Verification. Remediation: Create test files for each module. Priority: auth-store.test.ts, ProtectedRoute.test.tsx, LoginForm.test.tsx.

## Warnings (tech debt)

- [auth-store.ts:88] **Silently swallowed promise rejection in postTokenToWorker** — `.catch(() => {})` discards all errors from the service worker bridge. If the SW consistently fails, audio streaming silently breaks. Sources: TS/React Correctness, Test Verification. Remediation: Log at minimum: `.catch((err) => console.warn('SW token push failed:', err))`.

- [auth-store.ts:38] **Auth state is purely in-memory — page reload loses all tokens** — No `persist` middleware. Combined with DPoP keys in module-scoped variables, a page reload clears everything and the user is always logged out. May be intentional per-session design. Source: TS/React Correctness. Remediation: If intentional, add a comment confirming the design. If not, add Zustand `persist` with `partialize` or switch to httpOnly cookies.

- [LoginForm.tsx:28, RegisterForm.tsx:32] **Inconsistent error handling between forms** — LoginForm handles both OAuth-style `{error: string, error_description: string}` and project-style `{error: {code, message}}` errors. RegisterForm only handles the nested-object error shape. Both use inline type assertions instead of a shared helper. Sources: Component Architecture, TS/React Correctness. Remediation: Extract a shared `getApiErrorMessage(err: unknown): string | null` utility.

- [RegisterForm.tsx:24] **Hard-coded untranslated string** — `setError('Passwords do not match')` is a raw English string. The rest of the component uses `t()`. Source: Component Architecture. Remediation: Use `t('auth.passwordMismatch')`.

- [axios-instance.ts:113,159] **Unsafe type assertion on originalRequest.headers** — `headers = {} as InternalAxiosRequestConfig['headers']` discards all original request headers (Content-Type, Accept, etc.) on retry, not just overriding Authorization. Source: TS/React Correctness. Remediation: Only set `Authorization` without replacing the entire headers object.

- [axios-instance.ts:134] **Token refresh uses raw Axios, bypassing request interceptor** — Intentional to avoid 401 interceptor recursion, but DPoP proof construction is duplicated. Source: TS/React Correctness. Remediation: Add a comment explaining why. Consider using `AXIOS_INSTANCE` with `_skipAuth: true` for DPoP proof attachment.

- [axios-instance.ts:146] **setTokens called with potentially null sessionId during refresh** — After `clearAuth()`, `sessionId` is `null`, but `setTokens` sets `isAuthenticated: true`. Source: TS/React Correctness. Remediation: Capture `sessionId` before starting the refresh.

- [axios-instance.ts:165] **Hard redirect to /login on refresh failure** — `window.location.href = '/login'` causes a full page reload, bypassing React Router. May be intentional for security. Source: TS/React Correctness. Remediation: Verify this is the desired behavior.

- [auth-store.ts:77] **sessionId extraction from login response is dead code** — The backend `TokenResource` returns `{accessToken, tokenType, expiresIn, refreshToken}` — no `sessionId` field. `sessionId` is always `undefined`. Source: API Integration. Remediation: Add `sessionId` to `TokenResource` or remove it from the store.

- [auth-store.ts:46] **Race condition: concurrent login calls share isLoading flag** — Double-click submit causes both calls to set `isLoading: true`; the first to complete sets `isLoading: false` while the second is still running. Source: TS/React Correctness. Remediation: Guard with early return if `isLoading` is true.

## Info

- [LoginForm.tsx:55] Duplicated form field JSX pattern across LoginForm and RegisterForm — extract a `FormField` wrapper component.
- [LoginForm.tsx:57, RegisterForm.tsx:39] Inline error alert instead of shadcn `Alert` component.
- [LoginPage.tsx:14] Duplicated page layout with RegisterPage — extract `AuthLayout` wrapper.
- [LoginPage.tsx:15] Hard-coded app name "Bånder" — use `t('app.name')` or shared constant.
- [auth-store.ts:12] `User` interface defined locally — move to shared types file.
- [service-worker-bridge.ts:27] `initServiceWorkerListener` event listener never removed — acceptable for singleton but guard against duplicate registration.
- [LoginForm.tsx:27] `showTotp` closure is safe now but fragile if `useCallback` is added.
- [auth-store.ts:68] Status code check is redundant with Axios default behavior (non-2xx already rejected).
- [axios-instance.ts:109] Concurrent requests during refresh each retry individually — verify `_didRetry` flag propagates.
- [gen/endpoints/index.ts] Generated response types include `status`/`headers` fields absent at runtime due to `customInstance` unwrapping — type/implementation mismatch.
- [gen/endpoints/index.ts] Generated `ApiError` type mismatches actual backend error envelope structure.
- No frontend consumers for backend endpoints: password reset, profile (GET/PUT /me), email verification, TOTP setup/enable/disable.
- No frontend UI for TOTP management (login handles challenge, but no setup/enable/disable pages exist).

## Agent Findings Detail

### Component Architecture
- Inline error type assertions in both forms (fragile, duplicated, inconsistent)
- Duplicated JSX patterns (form fields, page layouts, error alerts)
- Hard-coded untranslated strings
- Type assertions on response data fields

### TS/React Correctness
- **CryptoKey serialization error** in service-worker-bridge — runtime `DataCloneError`
- Unsafe type assertions masking potential runtime failures
- Auth state purely in-memory with no persistence
- Token refresh header handling discards original headers
- Potential null sessionId during concurrent refresh failures

### API Integration Completeness
- **Login is completely broken** — `response.status` check always fails due to `customInstance` unwrapping
- **DPoP nonce extraction broken** — headers not available after unwrapping
- **Registration guaranteed to fail** — empty name violates NotBlank constraint
- Generated client types lie about runtime response shape
- 6 backend endpoints have no frontend consumer

### Test Verification
- Zero test coverage for entire auth feature (6 files)
- Critical untested paths: login success/failure, TOTP flow, ProtectedRoute guard, token refresh, error handling
- Security-critical components (ProtectedRoute) have zero verification
