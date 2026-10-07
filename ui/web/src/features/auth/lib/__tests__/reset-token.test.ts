import { describe, expect, it } from 'vitest'
import { readResetToken } from '../reset-token'

describe('readResetToken', () => {
  it('reads the token from the fragment', () => {
    expect(readResetToken('#token=9f86d081884c7d65')).toBe('9f86d081884c7d65')
  })

  it('returns null when the fragment carries no token', () => {
    expect(readResetToken('')).toBeNull()
    expect(readResetToken('#')).toBeNull()
    expect(readResetToken('#token=')).toBeNull()
    expect(readResetToken('#other=1')).toBeNull()
  })
})
