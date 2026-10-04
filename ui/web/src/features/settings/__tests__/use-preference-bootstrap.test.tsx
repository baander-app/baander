import { StrictMode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen } from '@testing-library/react'

const mocks = vi.hoisted(() => {
  const sync = () => ({
    conflict: { type: 'none' as 'none' | 'conflict', serverVersion: null as number | null },
    pushToServer: vi.fn(),
    fetchFromServer: vi.fn(),
    resolveConflict: vi.fn().mockResolvedValue(false),
  })
  return {
    audio: sync(), player: sync(), layout: sync(),
    auth: { isAuthenticated: true, user: { uuid: 'account-a' } as { uuid: string } | null },
    subscribe: vi.fn(), unsubscribe: vi.fn(),
    get: vi.fn(), mount: vi.fn(), cleanup: vi.fn(),
    isActive: undefined as (() => boolean) | undefined,
  }
})

vi.mock('../hooks/use-audio-preferences', async () => {
  const { useEffect } = await import('react')
  return {
    useAudioPreferences: (isActive: () => boolean) => {
      mocks.isActive = isActive
      useEffect(() => {
        mocks.mount()
        return () => mocks.cleanup()
      }, [])
      return mocks.audio
    },
  }
})
vi.mock('../hooks/use-player-preferences', () => ({ usePlayerPreferences: () => mocks.player }))
vi.mock('../hooks/use-layout-preferences', () => ({ useLayoutPreferences: () => mocks.layout }))
vi.mock('../hooks/use-theme-mood', () => ({ useThemeMood: () => ({ applyOnMount: vi.fn() }), VALID_MOODS: ['warm'] }))
vi.mock('../hooks/use-accent-color', () => ({ useAccentColor: () => ({ applyOnMount: vi.fn() }), VALID_COLORS: ['blue'] }))
vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: Object.assign((selector: (state: typeof mocks.auth) => unknown) => selector(mocks.auth), {
    getState: () => mocks.auth,
  }),
}))
vi.mock('@/features/equalizer/stores/eq-bands-store', () => ({ useEqBandsStore: { subscribe: mocks.subscribe } }))
vi.mock('@/features/equalizer/stores/eq-processing-store', () => ({ useEqProcessingStore: { subscribe: mocks.subscribe } }))
vi.mock('@/features/player/stores/player-store', () => ({ usePlayerStore: { subscribe: mocks.subscribe, getState: () => ({ volume: 42 }) } }))
vi.mock('@/features/layout/stores/context-panel-store', () => ({ useContextPanelStore: { subscribe: mocks.subscribe, getState: () => ({ mode: 'preview' }) } }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: mocks.get } }))
vi.mock('../components/PreferenceConflictDialog', () => ({
  PreferenceConflictDialog: ({ open, serverVersion, onResolve }: {
    open: boolean
    serverVersion: number | null
    onResolve: (resolution: 'mine' | 'theirs') => void
  }) => open ? <div role="dialog">
    <span>Version {serverVersion}</span>
    <button onClick={() => onResolve('mine')}>Keep mine</button>
    <button onClick={() => onResolve('theirs')}>Use theirs</button>
  </div> : null,
}))

import { PreferenceSyncProvider } from '../hooks/use-preference-bootstrap'

function provider() {
  return <PreferenceSyncProvider><span>Content</span></PreferenceSyncProvider>
}

describe('PreferenceSyncProvider conflicts', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.auth = { isAuthenticated: true, user: { uuid: 'account-a' } }
    mocks.subscribe.mockReturnValue(mocks.unsubscribe)
    mocks.get.mockResolvedValue({ data: {} })
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
    document.documentElement.removeAttribute('data-accent')
    for (const sync of [mocks.audio, mocks.player, mocks.layout]) {
      sync.conflict = { type: 'none', serverVersion: null }
      sync.resolveConflict = vi.fn().mockResolvedValue(false)
    }
  })

  it('shows audio, then player, then layout conflicts in priority order', () => {
    mocks.audio.conflict = { type: 'conflict', serverVersion: 1 }
    mocks.player.conflict = { type: 'conflict', serverVersion: 2 }
    mocks.layout.conflict = { type: 'conflict', serverVersion: 3 }
    const { rerender } = render(provider())
    expect(screen.getByText('Version 1')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Keep mine'))
    expect(mocks.audio.resolveConflict).toHaveBeenCalledWith('mine')

    mocks.audio.conflict = { type: 'none', serverVersion: null }
    rerender(provider())
    expect(screen.getByText('Version 2')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Keep mine'))
    expect(mocks.player.resolveConflict).toHaveBeenCalledWith('mine', { volume: 42 })

    mocks.player.conflict = { type: 'none', serverVersion: null }
    rerender(provider())
    expect(screen.getByText('Version 3')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Use theirs'))
    expect(mocks.layout.resolveConflict).toHaveBeenCalledWith('theirs', { mode: 'preview' })

    mocks.layout.conflict = { type: 'none', serverVersion: null }
    rerender(provider())
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('uses the latest resolver when the hook rerenders with the same conflict', () => {
    mocks.audio.conflict = { type: 'conflict', serverVersion: 4 }
    const originalResolver = mocks.audio.resolveConflict
    const { rerender } = render(provider())
    mocks.audio.resolveConflict = vi.fn().mockResolvedValue(true)
    rerender(provider())
    fireEvent.click(screen.getByText('Keep mine'))
    expect(originalResolver).not.toHaveBeenCalled()
    expect(mocks.audio.resolveConflict).toHaveBeenCalledWith('mine')
  })

  it.each(['Keep mine', 'Use theirs'])('keeps the dialog open when %s fails', async (button) => {
    mocks.audio.conflict = { type: 'conflict', serverVersion: 5 }
    render(provider())
    fireEvent.click(screen.getByText(button))
    await Promise.resolve()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    expect(screen.getByText('Version 5')).toBeInTheDocument()
  })

  it('remounts the sync session on account switch while preserving children', () => {
    const mountChild = vi.fn()
    function Child() {
      mountChild()
      return <span>Child</span>
    }
    const content = <Child />
    const { rerender } = render(<PreferenceSyncProvider>{content}</PreferenceSyncProvider>)
    const previousIsActive = mocks.isActive
    expect(mocks.mount).toHaveBeenCalledTimes(1)
    expect(mocks.audio.fetchFromServer).toHaveBeenCalledTimes(1)

    mocks.auth = { isAuthenticated: true, user: { uuid: 'account-b' } }
    // Auth changes invalidate the old callbacks even before React commits cleanup.
    expect(previousIsActive?.()).toBe(false)
    rerender(<PreferenceSyncProvider>{content}</PreferenceSyncProvider>)
    expect(mocks.cleanup).toHaveBeenCalledTimes(1)
    expect(mocks.unsubscribe).toHaveBeenCalledTimes(4)
    expect(mocks.mount).toHaveBeenCalledTimes(2)
    expect(mocks.audio.fetchFromServer).toHaveBeenCalledTimes(2)
    expect(mountChild).toHaveBeenCalledTimes(1)

    mocks.auth = { isAuthenticated: false, user: null }
    rerender(<PreferenceSyncProvider>{content}</PreferenceSyncProvider>)
    expect(mocks.cleanup).toHaveBeenCalledTimes(2)
    expect(mocks.unsubscribe).toHaveBeenCalledTimes(8)
    expect(screen.getByText('Child')).toBeInTheDocument()
  })

  it('starts sync when the user identity arrives after authentication', () => {
    mocks.auth = { isAuthenticated: true, user: null }
    const { rerender } = render(provider())
    expect(mocks.mount).not.toHaveBeenCalled()
    mocks.auth.user = { uuid: 'account-a' }
    rerender(provider())
    expect(mocks.mount).toHaveBeenCalledTimes(1)
    expect(mocks.audio.fetchFromServer).toHaveBeenCalledTimes(1)
  })

  it('aborts theme bootstrap and ignores a late theme response after account switch', async () => {
    let resolveMood: ((value: { data: { mood: string } }) => void) | undefined
    mocks.get.mockImplementationOnce(() => new Promise((resolve) => { resolveMood = resolve }))
    const { rerender } = render(provider())
    const firstConfig = mocks.get.mock.calls[0][1]
    expect(firstConfig.signal.aborted).toBe(false)
    mocks.auth = { isAuthenticated: true, user: { uuid: 'account-b' } }
    rerender(provider())
    expect(firstConfig.signal.aborted).toBe(true)
    await act(async () => { resolveMood?.({ data: { mood: 'warm' } }) })
    expect(localStorage.getItem('baander-theme-mood')).toBeNull()
    expect(document.documentElement.hasAttribute('data-theme')).toBe(false)
  })


  it('restarts bootstrap after StrictMode cleanup and ignores the aborted response', async () => {
    const moods: Array<(value: { data: { mood: string } }) => void> = []
    mocks.get.mockImplementation((url: string) => {
      if (url.endsWith('/theme-mood/')) {
        return new Promise((resolve) => { moods.push(resolve) })
      }
      return Promise.resolve({ data: { color: 'blue' } })
    })
    render(<StrictMode>{provider()}</StrictMode>)
    expect(mocks.audio.fetchFromServer).toHaveBeenCalledTimes(2)
    expect(mocks.get.mock.calls[0][1].signal.aborted).toBe(true)
    expect(moods).toHaveLength(2)

    await act(async () => { moods[0]({ data: { mood: 'warm' } }) })
    expect(localStorage.getItem('baander-theme-mood')).toBeNull()
    await act(async () => { moods[1]({ data: { mood: 'warm' } }) })
    expect(localStorage.getItem('baander-theme-mood')).toBe('warm')
    expect(localStorage.getItem('baander-accent-color')).toBe('blue')
    expect(document.documentElement.getAttribute('data-theme')).toBe('warm')
  })

})
