import { test, expect } from './worker-auth-harness'

// Mock proofs qualify browser transport; this does not validate backend DPoP cryptography.
test('plain native image authenticates and omits existing cookies', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/cover')).toBe('loaded')
  expect(harness.observations).toEqual([expect.objectContaining({
    host: 'api.baander.app', method: 'GET', authorization: 'DPoP transport-fixture-token',
    proof: 'transport-fixture-proof', cookie: null,
  })])
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
    expect.objectContaining({ method: 'GET', range: 'bytes=0-3', authorization: 'DPoP transport-fixture-token', proof: 'transport-fixture-proof', cookie: null }),
    expect.objectContaining({ method: 'HEAD', range: null, authorization: 'DPoP transport-fixture-token', proof: 'transport-fixture-proof', cookie: null }),
  ])
})

test('matching image path at a foreign origin receives no worker credentials', async ({ harness }) => {
  expect(await harness.image(harness.foreignOrigin + '/api/images/cover')).toBe('loaded')
  expect(harness.observations).toEqual([expect.objectContaining({
    host: 'foreign.baander.app', authorization: null, proof: null,
  })])
})

test('authenticated image redirect is blocked before contacting its foreign target', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/redirect')).toBe('failed')
  expect(harness.observations).toEqual([expect.objectContaining({
    host: 'api.baander.app', path: '/api/images/redirect', authorization: 'DPoP transport-fixture-token', proof: 'transport-fixture-proof', cookie: null,
  })])
})


test('expired native image refreshes once and retries with the replacement proof', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('loaded')
  expect(harness.refreshes).toEqual(['transport-fixture-token-refresh'])
  expect(harness.observations.map(item => item.authorization)).toEqual([
    'DPoP transport-fixture-token', 'DPoP transport-fixture-token-refresh-rotated',
  ])
  expect(JSON.parse(harness.observations[1].proof ?? '{}')).toMatchObject({token: 'transport-fixture-token-refresh-rotated',key: 'transport-fixture-token-key-1',method: 'GET'})
})

test('a second native 401 ends after one refresh and one retry', async ({ harness }) => {
  expect(await harness.image(harness.apiOrigin + '/api/images/always401')).toBe('failed')
  expect(harness.refreshes).toHaveLength(1)
  expect(harness.observations).toHaveLength(2)
})

test('refresh failure is bounded and follows the existing logout policy', async ({ harness, page }) => {
  await harness.configure(page, 'failure-token')
  harness.observations.length = 0
  await harness.image(harness.apiOrigin + '/api/images/expired').catch(() =>  'failed')
  await expect.poll(() => new URL(page.url()).pathname).toBe('/login')
  expect(harness.refreshes).toEqual(['failure-token-refresh'])
  expect(harness.observations).toHaveLength(1)
})

for (const replacement of [null, 'other-account'] as const) {
  test(`deferred native refresh cannot retry after ${replacement ? 'account switch' : 'logout'}`, async ({ harness, page }) => {
    const release = harness.gate('transport-fixture-token-refresh')
    const image = harness.image(harness.apiOrigin + '/api/images/expired')
    try {
      await expect.poll(() => harness.refreshes.length).toBe(1)
      if (replacement) await harness.configure(page, replacement)
      else await harness.clear(page)
    } finally { release() }
    expect(await image).toBe('failed')
    expect(await harness.token(page)).toBe(replacement)
    expect(harness.observations.filter(item => item.path.endsWith('/expired'))).toHaveLength(1)
  })
}

test('two controlled pages refresh only their own credentials and proofs', async ({ harness, page, context }) => {
  const other = await context.newPage()
  await harness.configure(other, 'second-account')
  harness.observations.length = 0
  const secondImage = other.evaluate(api=>new Promise(resolve=>{
    const image = new Image();image.onload=() => resolve('loaded');image.onerror=() => resolve('failed');image.src=api+'/api/images/expired';document.body.append(image)
  }),harness.apiOrigin)
  expect(await harness.image(harness.apiOrigin + '/api/images/expired')).toBe('loaded')
  expect(await secondImage).toBe('loaded')
  expect(harness.refreshes.sort()).toEqual(['second-account-refresh','transport-fixture-token-refresh'])
  expect(await harness.token(page)).toBe('transport-fixture-token-refresh-rotated')
  expect(await harness.token(other)).toBe('second-account-refresh-rotated')
  for (const refresh of harness.refreshProofs) {
    expect(JSON.parse(refresh.proof ?? '{}')).toMatchObject({key:refresh.token.replace(/-refresh$/, '-key-1'),method: 'POST',token:null})
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
  expect(harness.refreshes).toEqual(['transport-fixture-token-refresh'])
  const retries = harness.observations.filter(item => item.authorization?.endsWith('-rotated'))
  expect(retries).toHaveLength(2)
  for (const retry of retries) {
    expect(JSON.parse(retry.proof ?? '{}')).toMatchObject({
      token: 'transport-fixture-token-refresh-rotated', key: 'transport-fixture-token-key-1', method: 'GET',
    })
  }
})
