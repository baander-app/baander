import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'

vi.stubGlobal('crypto', {
  randomUUID: vi.fn(() => 'test-uuid-1234'),
})

import { useDeviceIdentity } from '../hooks/use-device-identity'

describe('useDeviceIdentity', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('returns existing device ID from localStorage', () => {
    localStorage.setItem('baander-device-id', 'existing-id')

    const { result } = renderHook(() => useDeviceIdentity())

    expect(result.current.deviceId).toBe('existing-id')
    expect(crypto.randomUUID).not.toHaveBeenCalled()
  })

  it('generates and stores new device ID when none exists', () => {
    const { result } = renderHook(() => useDeviceIdentity())

    expect(result.current.deviceId).toBe('test-uuid-1234')
    expect(localStorage.getItem('baander-device-id')).toBe('test-uuid-1234')
  })

  it('returns stable device ID across re-renders', () => {
    localStorage.setItem('baander-device-id', 'stable-id')

    const { result, rerender } = renderHook(() => useDeviceIdentity())
    const first = result.current.deviceId
    rerender()
    const second = result.current.deviceId

    expect(first).toBe(second)
  })
})
