import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useRetryCountdown } from '../use-retry-countdown'

describe('useRetryCountdown', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('counts down once a second and stops at zero', () => {
    const { result } = renderHook(() => useRetryCountdown())
    expect(result.current.secondsLeft).toBe(0)

    act(() => result.current.start(3))
    expect(result.current.secondsLeft).toBe(3)

    act(() => {
      vi.advanceTimersByTime(1000)
    })
    expect(result.current.secondsLeft).toBe(2)

    act(() => {
      vi.advanceTimersByTime(5000)
    })
    expect(result.current.secondsLeft).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('releases its timer on unmount', () => {
    const { result, unmount } = renderHook(() => useRetryCountdown())

    act(() => result.current.start(10))
    expect(vi.getTimerCount()).toBe(1)

    unmount()
    expect(vi.getTimerCount()).toBe(0)
  })
})
