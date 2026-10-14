/**
 * One refresh has one wall-clock budget.
 *
 * The window's refresh (`refreshSession` in `@/shared/api-client/axios-instance`) makes at most
 * four attempts inside REFRESH_BUDGET_MS: the first one, one more after a transport failure, one
 * more after a `use_dpop_nonce` challenge and one more after a 503 wait. An attempt is bounded by
 * REFRESH_ATTEMPT_TIMEOUT_MS or by what is left of the budget, whichever is shorter, and no attempt
 * starts once the budget is spent. The 503 wait is the server's `Retry-After` plus a uniform jitter
 * of up to the same value, capped at REFRESH_RETRY_AFTER_CAP_MS in total.
 *
 * Invariant: REFRESH_BUDGET_MS + REFRESH_RETRY_AFTER_CAP_MS stays below the server's lost-response
 * grace window, `auth.refresh.reuse_grace_seconds` in config/packages/auth.yaml (60 s). The server
 * answers a retried refresh with the same grant only inside that window, so a retry that starts at
 * the end of the budget, even after the longest wait, must still land inside it. The sum is a
 * conservative bound: the wait is already counted inside the budget.
 * tests/shared/auth/refresh-budget.test.ts reads the server default and checks the sum, and pins the
 * service worker's deadline constants to the values derived here.
 */
export const REFRESH_BUDGET_MS = 30_000;
export const REFRESH_ATTEMPT_TIMEOUT_MS = 10_000;
export const REFRESH_RETRY_AFTER_CAP_MS = 10_000;

/**
 * The stream service worker (src/features/player/services/auth-stream-worker.ts) is a single-file
 * build with no imports, so it declares the same values as its own named constants; the invariant
 * test keeps them equal.
 */

/** One message round trip between the worker and the window, on top of the window's budget. */
export const WORKER_MESSAGE_SLACK_MS = 5_000;

/** How long the worker waits for the window to answer a refresh request. */
export const WORKER_REFRESH_DEADLINE_MS = REFRESH_BUDGET_MS + WORKER_MESSAGE_SLACK_MS;

/** How long the worker waits for the window to sign one proof; signing is local, never a refresh. */
export const WORKER_PROOF_DEADLINE_MS = 3_000;

/**
 * Milliseconds to wait out a 503: `Retry-After` plus a uniform jitter of up to the same value, so
 * windows that failed together do not retry together, capped at REFRESH_RETRY_AFTER_CAP_MS in total.
 */
export function outageWaitMs(retryAfterSeconds: number, random: () => number = Math.random): number {
  return jitteredWaitMs(Math.max(0, retryAfterSeconds) * 1000, REFRESH_RETRY_AFTER_CAP_MS, random);
}

/** `baseMs` plus a uniform jitter of up to the same value, capped at `capMs` in total. */
function jitteredWaitMs(baseMs: number, capMs: number, random: () => number): number {
  return Math.min(capMs, baseMs + random() * baseMs);
}

/**
 * Pending logouts: a logout ends the session in the browser at once and its server request is
 * delivered afterwards, retried until the server confirms or refuses it. The delivery takes the
 * origin's auth lock per entry, waiting at most PENDING_LOGOUT_LOCK_WAIT_MS, and every one of its
 * requests carries REFRESH_ATTEMPT_TIMEOUT_MS, so a hung request cannot hold the lock past it.
 * While entries remain, a page retries on a timer that backs off from PENDING_LOGOUT_BACKOFF_MS by
 * PENDING_LOGOUT_BACKOFF_FACTOR up to PENDING_LOGOUT_BACKOFF_CAP_MS, with jitter, and at most
 * PENDING_LOGOUT_TIMER_ATTEMPTS times while the list stays non-empty; the start, `online` and
 * visibility triggers are not counted and reset the backoff, and an emptied list resets the count.
 * A server's Retry-After is honoured up to PENDING_LOGOUT_BACKOFF_CAP_MS.
 */
export const PENDING_LOGOUT_LOCK_WAIT_MS = 2_000;
export const PENDING_LOGOUT_BACKOFF_MS = 2_000;
export const PENDING_LOGOUT_BACKOFF_FACTOR = 2;
export const PENDING_LOGOUT_BACKOFF_CAP_MS = 300_000;
export const PENDING_LOGOUT_TIMER_ATTEMPTS = 8;

/**
 * Milliseconds until the next timer-driven delivery attempt after `step` consecutive ones: the
 * backoff plus a uniform jitter of up to the same value, capped at PENDING_LOGOUT_BACKOFF_CAP_MS.
 */
export function pendingLogoutBackoffMs(step: number, random: () => number = Math.random): number {
  const base = Math.min(PENDING_LOGOUT_BACKOFF_CAP_MS, PENDING_LOGOUT_BACKOFF_MS * PENDING_LOGOUT_BACKOFF_FACTOR ** Math.max(0, step));

  return jitteredWaitMs(base, PENDING_LOGOUT_BACKOFF_CAP_MS, random);
}
