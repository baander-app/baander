import { describe, expect, it } from 'vitest'
import { checkedClientRedirect } from '../client-redirect'
import { returnPathFrom, returnToState } from '../return-to'
import { formatUserCode, isCompleteUserCode, normalizeUserCode } from '../user-code'

describe('device user codes', () => {
  it('ignores case, spaces, and dashes', () => {
    expect(normalizeUserCode(' bcdf - ghjk ')).toBe('BCDFGHJK')
    expect(normalizeUserCode('BCDF-GHJK')).toBe('BCDFGHJK')
  })

  it('keeps at most eight characters', () => {
    expect(normalizeUserCode('BCDFGHJKLMN')).toBe('BCDFGHJK')
  })

  it('adds the dash once the first group is full', () => {
    expect(formatUserCode('BCD')).toBe('BCD')
    expect(formatUserCode('BCDF')).toBe('BCDF')
    expect(formatUserCode('BCDFG')).toBe('BCDF-G')
    expect(formatUserCode('BCDFGHJK')).toBe('BCDF-GHJK')
  })

  it('is complete with eight characters', () => {
    expect(isCompleteUserCode('BCDFGHJ')).toBe(false)
    expect(isCompleteUserCode('BCDFGHJK')).toBe(true)
  })
})

describe('return path after signing in', () => {
  it('returns to the guarded path with its query and fragment', () => {
    const state = returnToState({ pathname: '/device', search: '?user_code=BCDF-GHJK', hash: '#top' })

    expect(returnPathFrom(state)).toBe('/device?user_code=BCDF-GHJK#top')
  })

  it.each([
    ['no state', null],
    ['another state', { passwordReset: true }],
    ['a protocol-relative path', { from: { pathname: '//evil.baander.app/x', search: '', hash: '' } }],
    ['a backslash path', { from: { pathname: '/\\evil.baander.app', search: '', hash: '' } }],
    ['an absolute URL', { from: { pathname: 'https://evil.baander.app/', search: '', hash: '' } }],
    ['the login page', { from: { pathname: '/login', search: '', hash: '' } }],
    ['malformed fields', { from: { pathname: '/device', search: 1, hash: '' } }],
  ])('falls back to / for %s', (_label, state) => {
    expect(returnPathFrom(state)).toBe('/')
  })
})

describe('client redirect check', () => {
  const requested = 'https://app.baander.app/callback'

  it('accepts the requested URI with a code and state', () => {
    expect(checkedClientRedirect(`${requested}?code=abc&state=xyz`, requested))
      .toBe('https://app.baander.app/callback?code=abc&state=xyz')
  })

  it('accepts a native app scheme', () => {
    expect(checkedClientRedirect('app.baander.tv:/callback?code=abc', 'app.baander.tv:/callback'))
      .toBe('app.baander.tv:/callback?code=abc')
  })

  it('accepts any target when the request named no redirect URI', () => {
    expect(checkedClientRedirect('https://app.baander.app/only?code=abc', null))
      .toBe('https://app.baander.app/only?code=abc')
  })

  it.each([
    ['another host', 'https://evil.baander.app/callback?code=abc'],
    ['another path', 'https://app.baander.app/other?code=abc'],
    ['another port', 'https://app.baander.app:8443/callback?code=abc'],
    ['another scheme', 'http://app.baander.app/callback?code=abc'],
    ['a relative URL', '/callback?code=abc'],
  ])('refuses %s', (_label, candidate) => {
    expect(checkedClientRedirect(candidate, requested)).toBeNull()
  })

  it.each([
    'javascript:alert(1)',
    'data:text/html,<p>hi</p>',
    'vbscript:msgbox(1)',
  ])('refuses the script URL %s even without a requested URI', (candidate) => {
    expect(checkedClientRedirect(candidate, null)).toBeNull()
  })
})
