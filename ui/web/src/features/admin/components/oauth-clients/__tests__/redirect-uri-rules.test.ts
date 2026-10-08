import { describe, expect, it } from 'vitest'
import { parseRedirectUris, redirectUriProblem, redirectUrisProblem } from '../redirect-uri-rules'

describe('redirect URI hints', () => {
  it('reads one URI per line and skips blank lines', () => {
    expect(parseRedirectUris(' https://app.baander.app/cb \n\n app.baander.tv:/callback ')).toEqual([
      'https://app.baander.app/cb',
      'app.baander.tv:/callback',
    ])
  })

  it.each([
    'https://app.baander.app/callback',
    'http://127.0.0.1/cb',
    'http://localhost:8123/cb',
    'http://[::1]/cb',
    'app.baander.tv:/callback',
  ])('accepts %s', (uri) => {
    expect(redirectUriProblem(uri)).toBeNull()
  })

  it.each([
    ['a relative URI', '/callback', 'Not an absolute URI'],
    ['a fragment', 'https://app.baander.app/cb#top', 'Remove the fragment'],
    ['credentials', 'https://user:pass@app.baander.app/cb', 'Remove the credentials'],
    ['http on a public host', 'http://app.baander.app/cb', 'http only on a loopback host'],
    ['a scheme without a dot', 'baander:/callback', 'reverse domain form'],
    ['a script URL', 'javascript:alert(1)', 'reverse domain form'],
  ])('refuses %s', (_label, uri, message) => {
    expect(redirectUriProblem(uri)).toContain(message)
  })

  it('requires between one and ten URIs', () => {
    expect(redirectUrisProblem([])).toBe('Add at least one redirect URI.')
    expect(redirectUrisProblem(Array.from({ length: 11 }, (_, i) => `https://app.baander.app/cb${i}`)))
      .toBe('Use at most 10 redirect URIs.')
    expect(redirectUrisProblem(['https://app.baander.app/cb'])).toBeNull()
  })
})
