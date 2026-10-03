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
