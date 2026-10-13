/**
 * The scripted server's refresh state machine.
 *
 * A logout with a live access token revokes its chain; one with an unknown or a revoked token is
 * refused with 401, as the authenticator answers before the route runs. A refresh token of a
 * revoked chain is refused with 401 too: `/api/auth/refresh` answers every rejection that way.
 *
 * It answers presentations of refresh tokens the way `/api/auth/refresh` does on real PostgreSQL,
 * pinned to tests/Integration/RefreshTokenGraceTest.php: an unused token is consumed and rotated;
 * a used token presented again with its own key inside the grace window, while its replacement is
 * unused, gets a fresh pair and the replacement is revoked (the lost-response grace); a used token
 * whose replacement was used, or presented outside the window, revokes the chain; a superseded token
 * presented with its own key revokes the chain; a presentation with another key revokes nothing;
 * a presentation whose replacements are all revoked is refused with the chain intact.
 *
 * Tokens are named after the token they descend from, so a test reads the chain in the names:
 * `root` and `root-refresh` at login, `root-refresh-rotated` and `root-refresh-next` on the first
 * rotation, `root-refresh-graced` and `root-refresh-graced-next` when the lost response is graced.
 */

/** The server default, `auth.refresh.reuse_grace_seconds` in config/packages/auth.yaml. */
export const GRACE_WINDOW_MS = 60_000

export interface TokenPair {
  accessToken: string
  refreshToken: string
}

export type RefreshVerdict =
  | { status: 200; pair: TokenPair; reason: 'rotated' | 'grace' }
  | { status: 401; reason: 'unknown' | 'different key' | 'revoked' | 'outside window' | 'replacement used' | 'superseded token presented' | 'concurrent loser' }

export type AccessTokenStatus = 'unknown' | 'revoked' | 'initial' | 'rotated'

export type LogoutVerdict =
  | { status: 200 }
  | { status: 401; reason: 'missing' | 'unknown' | 'revoked' }

interface RefreshRecord {
  token: string
  chainId: string
  keyId: string
  previous: string | null
  usedAt: number | null
  revoked: boolean
  /** How many pairs were issued for this token: one rotation and then graces. */
  issued: number
}

interface AccessRecord {
  token: string
  chainId: string
  refreshToken: string
  revoked: boolean
  /** True for the pair a login issued; the scripted image routes treat it as expired. */
  initial: boolean
}

function pairName(presented: string, issue: number): TokenPair {
  if (issue === 1) {
    return { accessToken: `${presented}-rotated`, refreshToken: `${presented}-next` }
  }
  const suffix = issue === 2 ? 'graced' : `graced-${issue - 1}`
  return { accessToken: `${presented}-${suffix}`, refreshToken: `${presented}-${suffix}-next` }
}

export class RefreshState {
  private readonly refreshTokens = new Map<string, RefreshRecord>()
  private readonly accessTokens = new Map<string, AccessRecord>()
  private chains = 0
  private readonly graceWindowMs: number

  constructor(graceWindowMs = GRACE_WINDOW_MS) {
    this.graceWindowMs = graceWindowMs
  }

  /** A login: a new chain whose first pair is `root` and `root-refresh`, bound to `keyId`. */
  login(root: string, keyId: string): TokenPair {
    const pair = { accessToken: root, refreshToken: `${root}-refresh` }
    this.assertUnknown(pair)
    const chainId = `chain-${++this.chains}`
    this.refreshTokens.set(pair.refreshToken, {
      token: pair.refreshToken,
      chainId,
      keyId,
      previous: null,
      usedAt: null,
      revoked: false,
      issued: 0,
    })
    this.accessTokens.set(pair.accessToken, {
      token: pair.accessToken,
      chainId,
      refreshToken: pair.refreshToken,
      revoked: false,
      initial: true,
    })
    return pair
  }

  /** One presentation of `token` with the proof key `keyId`, judged at `now`. */
  present(token: string, keyId: string | null, now = Date.now()): RefreshVerdict {
    const record = this.refreshTokens.get(token)
    if (!record) return { status: 401, reason: 'unknown' }
    if (record.keyId !== keyId) return { status: 401, reason: 'different key' }
    if (record.revoked) {
      if (record.usedAt === null && this.liveUnusedRefreshTokens(record.chainId) > 0) {
        this.revokeChain(record.chainId)
        return { status: 401, reason: 'superseded token presented' }
      }
      return { status: 401, reason: 'revoked' }
    }
    if (record.usedAt === null) {
      record.usedAt = now
      return { status: 200, pair: this.issue(record), reason: 'rotated' }
    }
    if (now - record.usedAt >= this.graceWindowMs) {
      this.revokeChain(record.chainId)
      return { status: 401, reason: 'outside window' }
    }
    const successors = [...this.refreshTokens.values()].filter(candidate => candidate.previous === token)
    if (successors.some(successor => successor.usedAt !== null)) {
      this.revokeChain(record.chainId)
      return { status: 401, reason: 'replacement used' }
    }
    const live = successors.filter(successor => !successor.revoked)
    if (live.length === 0) return { status: 401, reason: 'concurrent loser' }
    for (const successor of live) {
      successor.revoked = true
      this.revokeAccessTokensOf(successor.token)
    }
    return { status: 200, pair: this.issue(record), reason: 'grace' }
  }

  /** What a resource server makes of a presented access token. */
  accessTokenStatus(token: string): AccessTokenStatus {
    const record = this.accessTokens.get(token)
    if (!record) return 'unknown'
    if (record.revoked) return 'revoked'
    return record.initial ? 'initial' : 'rotated'
  }

  /** A logout: a live `accessToken` revokes every token of its chain; an unknown or revoked one is refused. */
  logout(accessToken: string): LogoutVerdict {
    const record = this.accessTokens.get(accessToken)
    if (!record) return { status: 401, reason: 'unknown' }
    if (record.revoked) return { status: 401, reason: 'revoked' }
    this.revokeChain(record.chainId)
    return { status: 200 }
  }

  /** The chain of `refreshToken` was revoked elsewhere, as after a logout or an administrator's revocation. */
  revokeChainOfRefreshToken(refreshToken: string): boolean {
    const record = this.refreshTokens.get(refreshToken)
    if (!record) return false
    this.revokeChain(record.chainId)
    return true
  }

  isRefreshTokenRevoked(token: string): boolean {
    return this.refreshTokens.get(token)?.revoked ?? false
  }

  private issue(presented: RefreshRecord): TokenPair {
    const pair = pairName(presented.token, ++presented.issued)
    this.assertUnknown(pair)
    this.refreshTokens.set(pair.refreshToken, {
      token: pair.refreshToken,
      chainId: presented.chainId,
      keyId: presented.keyId,
      previous: presented.token,
      usedAt: null,
      revoked: false,
      issued: 0,
    })
    this.accessTokens.set(pair.accessToken, {
      token: pair.accessToken,
      chainId: presented.chainId,
      refreshToken: pair.refreshToken,
      revoked: false,
      initial: false,
    })
    return pair
  }

  private liveUnusedRefreshTokens(chainId: string): number {
    let count = 0
    for (const record of this.refreshTokens.values()) {
      if (record.chainId === chainId && !record.revoked && record.usedAt === null) count++
    }
    return count
  }

  private revokeChain(chainId: string): void {
    for (const record of this.refreshTokens.values()) {
      if (record.chainId === chainId) record.revoked = true
    }
    for (const record of this.accessTokens.values()) {
      if (record.chainId === chainId) record.revoked = true
    }
  }

  private revokeAccessTokensOf(refreshToken: string): void {
    for (const record of this.accessTokens.values()) {
      if (record.refreshToken === refreshToken) record.revoked = true
    }
  }

  private assertUnknown(pair: TokenPair): void {
    if (this.refreshTokens.has(pair.refreshToken) || this.accessTokens.has(pair.accessToken)) {
      throw new Error(`The scripted server already issued ${pair.accessToken} or ${pair.refreshToken}`)
    }
  }
}
