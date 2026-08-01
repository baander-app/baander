import { describe, it, expect, beforeEach, vi } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useMediaShortcuts } from '@/features/layout/hooks/use-media-shortcuts'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'

// Mock react-router-dom hooks since useMediaShortcuts uses useNavigate/useLocation
const mockNavigate = vi.fn()
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
    useLocation: () => ({ pathname: '/music' }),
  }
})

beforeEach(() => {
  localStorage.clear()
  useMediaModeStore.setState({ activeMedia: 'music' })
  mockNavigate.mockClear()
})

describe('useMediaShortcuts', () => {
  it('Cmd+1 switches to music', () => {
    useMediaModeStore.getState().setActiveMedia('movies')
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '1', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('music')
  })

  it('Cmd+2 switches to movies', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '2', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('movies')
  })

  it('Cmd+3 switches to tv', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '3', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('tv')
  })

  it('Cmd+4 switches to podcasts', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '4', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('podcasts')
  })

  it('Cmd+5 switches to concerts', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '5', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('concerts')
  })

  it('Cmd+6 switches to ebooks', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '6', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('ebooks')
  })

  it('does not switch when input is focused', () => {
    renderHook(() => useMediaShortcuts())

    const input = document.createElement('input')
    document.body.appendChild(input)
    input.focus()

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '2', metaKey: true }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('music')
    document.body.removeChild(input)
  })

  it('does not switch on number keys without meta', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '2' }))
    })

    expect(useMediaModeStore.getState().activeMedia).toBe('music')
  })

  it('navigates to media home when not already on it', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '2', metaKey: true }))
    })

    expect(mockNavigate).toHaveBeenCalledWith('/movies')
  })

  it('does not navigate when already on the media route', () => {
    renderHook(() => useMediaShortcuts())

    act(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: '1', metaKey: true }))
    })

    // Already on /music (mocked location), so no navigation
    expect(mockNavigate).not.toHaveBeenCalled()
  })
})
