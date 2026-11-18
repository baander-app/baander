import { act, cleanup, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useContextPanelStore } from '../../stores/context-panel-store'
import { useContextPanelSelection } from '../use-context-panel-selection'

const initialPlayer = usePlayerStore.getState()
const initialPanel = useContextPanelStore.getState()

function wrapper({ children }: { children: ReactNode }) {
  return <MemoryRouter>{children}</MemoryRouter>
}

const playing = (title: string) => ({ publicId: 'song-1', title, available: true })

beforeEach(() => {
  localStorage.clear()
  usePlayerStore.setState(initialPlayer, true)
  useContextPanelStore.setState({ ...initialPanel, layoutPreferencesRead: true }, true)
})

afterEach(() => {
  cleanup()
})

describe('selecting the playing track', () => {
  it('opens the panel on the Details tab when a track starts', () => {
    renderHook(() => useContextPanelSelection(), { wrapper })
    useContextPanelStore.getState().setOpen(false)

    act(() => usePlayerStore.setState({ currentTrack: playing('song-1') }))

    expect(useContextPanelStore.getState()).toMatchObject({
      selectedItem: { type: 'song', publicId: 'song-1' }, activeTab: 'details', isOpen: true,
    })
  })

  it('keeps a panel the user closed closed when the same track only gains its details', () => {
    renderHook(() => useContextPanelSelection(), { wrapper })
    act(() => usePlayerStore.setState({ currentTrack: playing('song-1') }))
    act(() => useContextPanelStore.getState().setOpen(false))

    act(() => usePlayerStore.setState({ currentTrack: playing('A song with its real title') }))

    expect(useContextPanelStore.getState().isOpen).toBe(false)
  })

  it('opens the panel again for the next track', () => {
    renderHook(() => useContextPanelSelection(), { wrapper })
    act(() => usePlayerStore.setState({ currentTrack: playing('song-1') }))
    act(() => useContextPanelStore.getState().setOpen(false))

    act(() => usePlayerStore.setState({ currentTrack: { publicId: 'song-2', title: 'Next', available: true } }))

    expect(useContextPanelStore.getState()).toMatchObject({
      selectedItem: { type: 'song', publicId: 'song-2' }, isOpen: true,
    })
  })

  it('keeps the tab of a panel whose saved layout has not been read yet', () => {
    useContextPanelStore.setState({ layoutPreferencesRead: false, activeTab: 'queue' })
    renderHook(() => useContextPanelSelection(), { wrapper })

    act(() => usePlayerStore.setState({ currentTrack: playing('song-1') }))

    expect(useContextPanelStore.getState()).toMatchObject({
      selectedItem: { type: 'song', publicId: 'song-1' }, activeTab: 'queue', isOpen: true,
    })
  })
})
