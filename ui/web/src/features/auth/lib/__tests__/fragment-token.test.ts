import { describe, expect, it } from 'vitest'
import { readFragmentToken } from '../fragment-token'

describe('readFragmentToken', () => {
  it('reads the token from the fragment', () => {
    expect(readFragmentToken('#token=9f86d081884c7d65')).toBe('9f86d081884c7d65')
  })

  it('returns null when the fragment carries no token', () => {
    expect(readFragmentToken('')).toBeNull()
    expect(readFragmentToken('#')).toBeNull()
    expect(readFragmentToken('#token=')).toBeNull()
    expect(readFragmentToken('#other=1')).toBeNull()
  })
})
