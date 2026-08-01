import { describe, it, expect, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { useMediaMode } from '@/features/layout/hooks/use-media-mode'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'

beforeEach(() => {
  localStorage.clear()
  useMediaModeStore.setState({ activeMedia: 'music' })
})

function wrapper({ children }: { children: React.ReactNode }) {
  return <MemoryRouter initialEntries={['/music']}>{children}</MemoryRouter>
}

describe('useMediaMode', () => {
  it('switching media type updates activeMedia in store', () => {
    const { result } = renderHook(() => useMediaMode(), { wrapper })
    result.current.switchMedia('movies')
    expect(useMediaModeStore.getState().activeMedia).toBe('movies')
  })

  it('returns current active media', () => {
    const { result } = renderHook(() => useMediaMode(), { wrapper })
    expect(result.current.activeMedia).toBe('music')
  })
})
