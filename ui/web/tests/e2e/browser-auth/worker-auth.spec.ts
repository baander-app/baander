import { test, expect } from './worker-auth-harness'

// Mock proofs qualify browser transport; this does not validate backend DPoP cryptography.
test('plain native image authenticates and omits existing cookies', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/cover')).toBe('loaded')
  expect(harness.observations).toEqual([
    expect.objectContaining({
      host: 'api.baander.app',
      method: 'GET',
      authorization: 'DPoP transport-fixture-token',
      proof: 'transport-fixture-proof',
      cookie: null,
    }),
  ])
})

test('authorized Range GET and HEAD preserve their request semantics without cookies', async ({ harness, page }) => {
  const ranged = await page.evaluate(async api => {
    const response = await fetch(api + '/api/stream/range', { headers: { Range: 'bytes=0-3' }, credentials: 'include' })
    return { status: response.status, contentRange: response.headers.get('Content-Range'), body: await response.text() }
  }, harness.apiOrigin)
  expect(ranged).toEqual({ status: 206, contentRange: 'bytes 0-3/8', body: 'abcd' })

  const head = await page.evaluate(async api => {
    const response = await fetch(api + '/api/stream/head', { method: 'HEAD', credentials: 'include' })
    return { status: response.status, body: await response.text() }
  }, harness.apiOrigin)
  expect(head).toEqual({ status: 200, body: '' })

  expect(harness.observations).toEqual([
    expect.objectContaining({
      method: 'GET',
      range: 'bytes=0-3',
      authorization: 'DPoP transport-fixture-token',
      proof: 'transport-fixture-proof',
      cookie: null,
    }),
    expect.objectContaining({
      method: 'HEAD',
      range: null,
      authorization: 'DPoP transport-fixture-token',
      proof: 'transport-fixture-proof',
      cookie: null,
    }),
  ])
})

test('matching image path at a foreign origin receives no worker credentials', async ({ harness }) => {
  expect(await harness.image(harness.foreignOrigin + '/api/images/cover')).toBe('loaded')
  expect(harness.observations).toEqual([
    expect.objectContaining({ host: 'foreign.baander.app', authorization: null, proof: null }),
  ])
})

test('authenticated image redirect is blocked before contacting its foreign target', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/redirect')).toBe('failed')
  expect(harness.observations).toEqual([
    expect.objectContaining({
      host: 'api.baander.app',
      path: '/api/images/redirect',
      authorization: 'DPoP transport-fixture-token',
      proof: 'transport-fixture-proof',
      cookie: null,
    }),
  ])
})

test('expired native image refreshes once and retries with the replacement proof', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('loaded')
  expect(harness.refreshes).toEqual([
    { token: 'transport-fixture-token-refresh', key: 'transport-fixture-token-key', nonce: null, status: 200 },
  ])
  expect(harness.observations.map(item => item.authorization)).toEqual([
    'DPoP transport-fixture-token',
    'DPoP transport-fixture-token-refresh-rotated',
  ])
  expect(JSON.parse(harness.observations[1].proof ?? '{}')).toMatchObject({
    token: 'transport-fixture-token-refresh-rotated',
    key: 'transport-fixture-token-key',
    method: 'GET',
  })
})

test('a second native 401 ends after one refresh and one retry', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/always401')).toBe('failed')
  expect(harness.refreshes).toHaveLength(1)
  expect(harness.observations).toHaveLength(2)
})

test('a refresh the server refuses ends the session on the login page after one attempt', async ({ harness, page }) => {
  // The chain was revoked elsewhere, as after a logout on another device.
  expect(harness.refreshState.revokeChainOfRefreshToken('transport-fixture-token-refresh')).toBe(true)
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('failed')
  await expect.poll(() => new URL(page.url()).pathname).toBe('/login')
  expect(harness.refreshes).toEqual([
    { token: 'transport-fixture-token-refresh', key: 'transport-fixture-token-key', nonce: null, status: 401 },
  ])
  expect(harness.observations).toHaveLength(1)
})

for (const replacement of [null, 'other-account'] as const) {
  test(`deferred native refresh cannot retry after ${replacement ? 'account switch' : 'logout'}`, async ({ harness, page }) => {
    const release = harness.gate('transport-fixture-token-refresh')
    const image = harness.image(harness.apiOrigin + '/api/images/expired')
    try {
      await expect.poll(() => harness.refreshes.length).toBe(1)
      if (replacement) {
        await harness.configure(page, replacement)
      } else {
        await harness.clear(page)
      }
    } finally {
      release()
    }
    expect(await image).toBe('failed')
    expect(await harness.token(page)).toBe(replacement)
    expect(harness.observations.filter(item => item.path.endsWith('/expired'))).toHaveLength(1)
  })
}

test('two controlled pages refresh only their own credentials and proofs', async ({ harness, page, context }) => {
  const other = await context.newPage()
  await harness.configure(other, 'second-account')
  harness.observations.length = 0

  const secondImage = harness.image(harness.apiOrigin + '/api/images/expired', other)
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('loaded')
  expect(await secondImage).toBe('loaded')

  expect(harness.refreshes.map(refresh => refresh.token).sort()).toEqual([
    'second-account-refresh',
    'transport-fixture-token-refresh',
  ])
  expect(await harness.token(page)).toBe('transport-fixture-token-refresh-rotated')
  expect(await harness.token(other)).toBe('second-account-refresh-rotated')
  for (const refresh of harness.refreshes) {
    expect(refresh.key).toBe(refresh.token.replace(/-refresh$/, '-key'))
  }
  for (const item of harness.observations.filter(item => item.authorization?.endsWith('-rotated'))) {
    expect(JSON.parse(item.proof ?? '{}').token).toBe(item.authorization?.slice(5))
  }
})

test('concurrent native expiries in one page share one refresh and both retry', async ({ harness }) => {
  const release = harness.gate('transport-fixture-token-refresh')
  const images = [1, 2].map(id => harness.image(`${harness.apiOrigin}/api/images/expired?parallel=${id}`))
  try {
    await expect.poll(() => harness.observations.filter(item => item.path.endsWith('/expired')).length).toBe(2)
    await expect.poll(() => harness.refreshes.length).toBe(1)
  } finally {
    release()
  }
  expect(await Promise.all(images)).toEqual(['loaded', 'loaded'])
  expect(harness.refreshes.map(refresh => refresh.token)).toEqual(['transport-fixture-token-refresh'])

  const retries = harness.observations.filter(item => item.authorization?.endsWith('-rotated'))
  expect(retries).toHaveLength(2)
  for (const retry of retries) {
    expect(JSON.parse(retry.proof ?? '{}')).toMatchObject({
      token: 'transport-fixture-token-refresh-rotated',
      key: 'transport-fixture-token-key',
      method: 'GET',
    })
  }
})

test('a refresh answers one nonce challenge and succeeds with the issued nonce', async ({ harness }) => {
  harness.setRefreshMode('nonce-challenge')
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('loaded')
  expect(harness.refreshes).toEqual([
    { token: 'transport-fixture-token-refresh', key: 'transport-fixture-token-key', nonce: null, status: 400 },
    { token: 'transport-fixture-token-refresh', key: 'transport-fixture-token-key', nonce: 'nonce-1', status: 200 },
  ])
  expect(harness.observations.map(item => item.authorization)).toEqual([
    'DPoP transport-fixture-token',
    'DPoP transport-fixture-token-refresh-rotated',
  ])
})

// AE1: the first refresh rotated the token on the server, but its answer never arrived.
test('a lost refresh response is retried with the same token and graced; the next image loads', async ({ harness, page }) => {
  harness.setRefreshMode('lose-next-response')
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('loaded')
  expect(harness.refreshes).toEqual([
    { token: 'transport-fixture-token-refresh', key: 'transport-fixture-token-key', nonce: null, status: 'lost' },
    { token: 'transport-fixture-token-refresh', key: 'transport-fixture-token-key', nonce: null, status: 200 },
  ])
  expect(await harness.token(page)).toBe('transport-fixture-token-refresh-graced')
  expect(harness.refreshState.isRefreshTokenRevoked('transport-fixture-token-refresh-next')).toBe(true)
  expect(harness.refreshState.accessTokenStatus('transport-fixture-token-refresh-rotated')).toBe('revoked')

  expect(await harness.image(harness.apiOrigin + '/api/images/cover')).toBe('loaded')
  expect(harness.observations.map(item => item.authorization)).toEqual([
    'DPoP transport-fixture-token',
    'DPoP transport-fixture-token-refresh-graced',
    'DPoP transport-fixture-token-refresh-graced',
  ])
})

// AE4: the nonce store is down; the refresh is answered 503 with Retry-After and the session is kept.
test('a 503 refresh waits Retry-After, retries once, keeps the session on a second 503 and succeeds once the outage ends', async ({ harness, page }) => {
  await page.clock.install()
  harness.setRefreshMode('outage')
  const image = harness.image(harness.apiOrigin + '/api/images/expired')
  await expect.poll(() => harness.refreshes.length).toBe(1)

  // Retry-After is 5 s: four seconds later nothing has been retried.
  await page.clock.fastForward(4_000)
  expect(harness.refreshes).toHaveLength(1)
  // The longest wait is Retry-After plus the same jitter, capped at ten seconds.
  await page.clock.fastForward(6_000)
  await expect.poll(() => harness.refreshes.length).toBe(2)

  expect(await image).toBe('failed')
  expect(harness.refreshes.map(refresh => refresh.status)).toEqual([503, 503])
  expect(await harness.token(page)).toBe('transport-fixture-token')
  expect(new URL(page.url()).pathname).toBe('/')

  harness.setRefreshMode('normal')
  expect(await harness.image(harness.apiOrigin + '/api/images/expired?after=outage')).toBe('loaded')
  expect(harness.refreshes.map(refresh => refresh.status)).toEqual([503, 503, 200])
  expect(await harness.token(page)).toBe('transport-fixture-token-refresh-rotated')
})

// AE7: the browser stopped the idle worker; the next image makes it pull its credentials from the page.
test('a worker stopped by the browser pulls its credentials from the page once and signs the next image', async ({ harness, page }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/cover?before=stop')).toBe('loaded')
  await harness.stopWorker(page)

  expect(await harness.image(harness.apiOrigin + '/api/images/cover?after=stop')).toBe('loaded')
  expect(await harness.pulls(page)).toBe(1)
  expect(await harness.image(harness.apiOrigin + '/api/images/cover?after=pull')).toBe('loaded')
  expect(await harness.pulls(page)).toBe(1)

  expect(harness.observations.map(item => item.authorization)).toEqual([
    'DPoP transport-fixture-token',
    'DPoP transport-fixture-token',
    'DPoP transport-fixture-token',
  ])
  expect(harness.observations.map(item => item.proof)).toEqual([
    'transport-fixture-proof',
    'transport-fixture-proof',
    'transport-fixture-proof',
  ])
  expect(harness.observations.map(item => item.cookie)).toEqual([null, null, null])
})

test('foreign-origin exclusion holds after a worker stop: no credentials and no pull for the foreign image', async ({ harness, page }) => {
  await harness.stopWorker(page)
  // The page becomes known to the restarted worker through the one pull its own image takes.
  expect(await harness.image(harness.apiOrigin + '/api/images/cover')).toBe('loaded')
  expect(await harness.pulls(page)).toBe(1)

  expect(await harness.image(harness.foreignOrigin + '/api/images/cover')).toBe('loaded')
  expect(await harness.pulls(page)).toBe(1)
  expect(harness.observations).toEqual([
    expect.objectContaining({ host: 'api.baander.app', authorization: 'DPoP transport-fixture-token', proof: 'transport-fixture-proof' }),
    expect.objectContaining({ host: 'foreign.baander.app', authorization: null, proof: null }),
  ])
})

test.describe('two pages of one account on the real auth store', () => {
  test.use({ store: 'real' })

  // AE5: the second page waits for the lock, reads the rotation the first page wrote, and adopts it.
  test('the second page adopts the first page\'s rotation and never calls the refresh endpoint', async ({ harness, page }) => {
    await harness.login(page, 'listener@baander.app')
    const other = await harness.openPage()
    expect(await harness.token(other)).toBe('listener')

    const release = harness.gate('listener-refresh')
    const first = harness.image(harness.apiOrigin + '/api/images/expired', page)
    await expect.poll(() => harness.refreshes.length).toBe(1)
    const second = harness.image(harness.apiOrigin + '/api/images/expired', other)
    await expect.poll(() => harness.observations.filter(item => item.path.endsWith('/expired')).length).toBe(2)
    release()

    expect(await Promise.all([first, second])).toEqual(['loaded', 'loaded'])
    expect(harness.refreshes).toEqual([
      expect.objectContaining({ token: 'listener-refresh', status: 200 }),
    ])
    expect(await harness.token(page)).toBe('listener-refresh-rotated')
    expect(await harness.token(other)).toBe('listener-refresh-rotated')
    const retries = harness.observations.filter(item => item.authorization === 'DPoP listener-refresh-rotated')
    expect(retries).toHaveLength(2)
  })

  // AE6: the logout revokes the chain once; the other page leaves without asking the server.
  test('a logout in one page clears the other page without a server call and both workers drop their credentials', async ({ harness, page }) => {
    await harness.login(page, 'listener@baander.app')
    const other = await harness.openPage()
    expect(await harness.image(harness.apiOrigin + '/api/images/cover', other)).toBe('loaded')
    harness.observations.length = 0
    harness.authCalls.length = 0

    await harness.logout(page)
    await expect.poll(() => new URL(other.url()).pathname).toBe('/login')
    expect(harness.authCalls).toEqual([
      { path: '/api/auth/logout', authorization: 'DPoP listener', status: 200 },
    ])
    expect(harness.refreshes).toEqual([])
    expect(harness.refreshState.accessTokenStatus('listener')).toBe('revoked')
    expect(await harness.token(page)).toBeNull()
    expect(await harness.token(other)).toBeNull()

    expect(await harness.image(harness.apiOrigin + '/api/images/cover?page=a', page)).toBe('failed')
    expect(await harness.image(harness.apiOrigin + '/api/images/cover?page=b', other)).toBe('failed')
    expect(harness.observations.map(item => [item.authorization, item.proof])).toEqual([
      [null, null],
      [null, null],
    ])
    expect(harness.authCalls).toHaveLength(1)
  })
})
