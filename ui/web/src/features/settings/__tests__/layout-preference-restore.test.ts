import { act, cleanup, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { registerContextPanelHandlers } from '@/features/layout/stores/context-panel-handlers'
import { useLayoutPreferences } from '../hooks/use-layout-preferences'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: http }))

registerContextPanelHandlers()

describe('layout preference restoration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useContextPanelStore.setState(useContextPanelStore.getInitialState(), true)
  })
  afterEach(cleanup)

  it('restores mode and tab without changing transient panel selection or visibility', async () => {
    const selectedItem = { type: 'album' as const, publicId: 'album-1' }
    useContextPanelStore.setState({ selectedItem, isOpen: false, width: 320 })
    http.get.mockResolvedValue({ data: { data: { payload: { mode: 'compact', activeTab: 'lyrics' }, version: 4 } } })
    const { result } = renderHook(() => useLayoutPreferences())
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(true) })
    expect(useContextPanelStore.getState()).toMatchObject({
      mode: 'compact', activeTab: 'lyrics', selectedItem, isOpen: false, width: 320,
    })
    expect(result.current.versionRef.current).toBe(4)
  })

  it('restores the selected history entry tab', async () => {
    http.post.mockResolvedValue({ data: { data: { payload: { mode: 'expanded', activeTab: 'info' }, version: 7 } } })
    const { result } = renderHook(() => useLayoutPreferences())
    await act(async () => { expect(await result.current.rollback(2)).toBe(true) })
    expect(useContextPanelStore.getState().activeTab).toBe('info')
  })

  it.each([
    { mode: 'pioneer', activeTab: 'queue' },
    { mode: 'expanded', activeTab: 'library' },
    { mode: 'expanded' },
    { mode: null, activeTab: 'lyrics' },
  ])('rejects an unsupported remote layout without changing state: %j', async (payload) => {
    const before = useContextPanelStore.getState()
    http.get.mockResolvedValue({ data: { data: { payload, version: 4 } } })
    const { result } = renderHook(() => useLayoutPreferences())
    await act(async () => { expect(await result.current.fetchFromServer()).toBe(false) })
    expect(useContextPanelStore.getState()).toBe(before)
    expect(result.current.versionRef.current).toBe(0)
  })

})
