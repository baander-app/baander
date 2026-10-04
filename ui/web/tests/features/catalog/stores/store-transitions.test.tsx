import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useViewModeStore } from '@/features/catalog/stores/view-mode-store'
import { useSelectionStore } from '@/features/catalog/stores/selection-store'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useNotificationStore } from '@/features/notification/stores/notification-store'
import { useEqCompareStore } from '@/features/equalizer/stores/eq-compare-store'
import { useEqProfilesStore, type EqProfile } from '@/features/equalizer/stores/eq-profiles-store'

beforeEach(() => {
  useViewModeStore.setState(useViewModeStore.getInitialState(), true)
  useSelectionStore.getState().clear()
  useSidebarStore.setState(useSidebarStore.getInitialState(), true)
  useNotificationStore.setState(useNotificationStore.getInitialState(), true)
  useEqCompareStore.getState().clearAll()
  useEqProfilesStore.setState(useEqProfilesStore.getInitialState(), true)
})

describe('feature store transitions', () => {
  it('does not notify or write persistence on unchanged public or native transitions', () => {
    const write = vi.spyOn(Storage.prototype, 'setItem')
    const notify = vi.fn()
    const unsubscribe = useViewModeStore.subscribe(notify)
    useViewModeStore.getState().setViewMode('grid')
    useViewModeStore.getState().setColumnSplitPx(null)
    useViewModeStore.setState({ viewMode: 'grid' })
    expect(notify).not.toHaveBeenCalled()
    expect(write).not.toHaveBeenCalled()
    useViewModeStore.getState().setViewMode('list')
    expect(notify).toHaveBeenCalledOnce()
    expect(write).toHaveBeenCalledOnce()
    unsubscribe()
    write.mockRestore()
  })

  it('keeps scalar selectors isolated from other fields and repeated selections', () => {
    let renders = 0
    const { result } = renderHook(() => {
      renders++
      return useSelectionStore((state) => state.selectedId)
    })
    act(() => useSelectionStore.getState().select('album-1', 'album'))
    expect(result.current).toBe('album-1')
    const afterSelection = renders
    act(() => {
      useSelectionStore.getState().select('album-1', 'album')
      useSelectionStore.getState().select('album-1', 'artist')
    })
    expect(renders).toBe(afterSelection)
  })

  it('does not rerender an action selector for sidebar loading and schema changes', () => {
    let renders = 0
    renderHook(() => { renders++; return useSidebarStore((state) => state.setItems) })
    act(() => {
      useSidebarStore.getState().setLoading(true)
      useSidebarStore.getState().setEditorOpen(true)
    })
    expect(renders).toBe(1)
  })

  it('counts each notification once and ignores missing/already-read read transitions', () => {
    const notification = { publicId: 'notification-1', category: 'security' as const, eventType: 'login', title: null,
      body: null, parameters: null, isRead: false, createdAt: '2026-10-04T00:00:00Z' }
    const state = useNotificationStore.getState()
    state.addNotification(notification)
    const notify = vi.fn(), unsubscribe = useNotificationStore.subscribe(notify)
    state.addNotification(notification)
    state.markRead('missing')
    expect(notify).not.toHaveBeenCalled()
    expect(useNotificationStore.getState().unreadCount).toBe(1)
    state.markRead(notification.publicId)
    expect(useNotificationStore.getState().unreadCount).toBe(0)
    state.markRead(notification.publicId)
    state.markAllRead()
    expect(notify).toHaveBeenCalledOnce()
    unsubscribe()
  })

  it('retains the active comparison when the other slot is cleared', () => {
    const snapshot = { id: 'snapshot', label: 'A', timestamp: 0, bands: [], processing: {} }
    useEqCompareStore.getState().captureSlot('A', snapshot)
    useEqCompareStore.getState().clearSlot('B')
    expect(useEqCompareStore.getState().activeSlot).toBe('A')
    expect(useEqCompareStore.getState().slotA).toBe(snapshot)
  })

  it('never selects a removed default profile as the replacement', () => {
    const profile: EqProfile = { id: 'default', name: 'Default', icon: 'headphones', payload: {}, isDefault: true, sortOrder: 0, version: 1 }
    useEqProfilesStore.getState().setProfiles([profile])
    useEqProfilesStore.getState().setActiveProfileId(profile.id)
    useEqProfilesStore.getState().removeProfile(profile.id)
    expect(useEqProfilesStore.getState().activeProfileId).toBeNull()
    expect(useEqProfilesStore.getState().profiles).toEqual([])
  })
})
