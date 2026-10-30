import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { AxiosHeaders, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { LiveCoordinatorContext } from '@/shared/live/context'
import { LiveQueryCoordinator } from '@/shared/live/query-coordinator'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { settingsLiveDescriptors } from '@/features/settings/live-descriptors'
import { usePreferenceSync } from '@/features/settings/hooks/use-preference-sync'
import { usePasskeyList, useDeletePasskey } from '@/features/settings/hooks/use-passkey-list'
import { ProfileSelector } from '@/features/equalizer/components/ProfileSelector'
import { useEqProfilesStore } from '@/features/equalizer/stores/eq-profiles-store'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { SidebarEditor } from '@/features/layout/components/SidebarEditor'
import { useSidebarConfig } from '@/features/layout/hooks/use-sidebar-config'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useMediaModeStore } from '@/features/layout/stores/media-mode-store'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import type { LiveSnapshot } from '@/shared/live/recovery'

const previousAdapter = AXIOS_INSTANCE.defaults.adapter
const disposals: Array<() => void> = []
const response = (config: InternalAxiosRequestConfig, data: unknown): AxiosResponse => ({
  config, data, status: 200, statusText: 'OK', headers: new AxiosHeaders(),
})
function setup() {
  const client = new OwnedQueryClient()
  const snapshot: LiveSnapshot = {
    status: 'current', authorityReady: true, families: ['preferences', 'account'],
    authorizationFingerprint: 'a'.repeat(64), health: { state: 'current', oldestPendingAgeMs: null },
  }
  const resourceReadFailed = vi.fn()
  const coordinator = new LiveQueryCoordinator(client, {
    getSnapshot: () => snapshot, subscribe: () => () => undefined,
    subscribeEvents: () => () => undefined, completeRecovery: () => undefined,
    resourceReadFailed,
  }, settingsLiveDescriptors)
  client.setCoordinator(coordinator)
  disposals.push(coordinator.start(), () => client.dispose())
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={client}>
      <LiveCoordinatorContext.Provider value={coordinator}>{children}</LiveCoordinatorContext.Provider>
    </QueryClientProvider>
  )
  return { client, coordinator, wrapper, resourceReadFailed }
}
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
beforeEach(() => {
  synchronizeOwnership(null)
  useEqProfilesStore.getState().resetForPrincipal()
  useSidebarStore.setState(useSidebarStore.getInitialState(), true)
  useMediaModeStore.getState().setActiveMedia('music')
})
afterEach(() => {
  cleanup()
  disposals.splice(0).forEach(dispose => dispose())
  AXIOS_INSTANCE.defaults.adapter = previousAdapter
  vi.useRealTimers()
  vi.restoreAllMocks()
})

it('refreshes preference snapshots through Axios without echoing remote application, and protects dirty edits', async () => {
  let version = 1
  let volume = 10
  const puts: unknown[] = []
  const reads: AbortSignal[] = []
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') {
      puts.push(JSON.parse(String(config.data)))
      volume = JSON.parse(String(config.data)).payload.volume
      version++
    } else reads.push(config.signal as AbortSignal)
    return response(config, { data: { version, payload: { volume } } })
  }
  const { coordinator, wrapper } = setup()
  const apply = vi.fn()
  const hook = renderHook(() => usePreferenceSync({
    baseUrl: '/api/user/player-preferences/', toPayload: (state: { volume: number }) => state,
    fromPayload: payload => ({ volume: Number(payload.volume) }), onRemoteUpdate: apply,
  }), { wrapper })
  apply.mockImplementation(state => hook.result.current.pushToServer(state))
  await waitFor(() => expect(apply).toHaveBeenCalledWith({ volume: 10 }, 1))
  volume = 30; version = 2
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(apply).toHaveBeenCalledWith({ volume: 30 }, 2))
  expect(puts).toEqual([])
  act(() => hook.result.current.pushToServer({ volume: 80 }))
  await act(async () => { expect(await hook.result.current.fetchFromServer()).toBe(false) })
  expect(apply).toHaveBeenCalledTimes(2)
  await waitFor(() => expect(puts).toEqual([{ payload: { volume: 80 }, version: 2 }]))
  await waitFor(() => expect(apply).toHaveBeenCalledWith({ volume: 80 }, 3))
  expect(reads.every(signal => signal instanceof AbortSignal)).toBe(true)
})

it('refreshes the EQ list without selecting or applying a profile, and fences late offline fallback', async () => {
  let name = 'Studio'
  const pending = deferred<AxiosResponse>()
  let savedConfig: InternalAxiosRequestConfig | undefined
  const commands: string[] = []
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') { savedConfig = config; return pending.promise }
    if (config.method !== 'get') commands.push(config.url ?? '')
    return response(config, { data: { profiles: [{ publicId: 'profile', name, icon: 'custom', isDefault: true, payload: { enabled: true }, sortOrder: 0, version: 1 }] } })
  }
  const { coordinator, wrapper } = setup()
  const audioBefore = useEqBandsStore.getState()
  useEqProfilesStore.getState().setActiveProfileId('chosen')
  render(<ProfileSelector />, { wrapper })
  await screen.findByText('Studio')
  name = 'Renamed remotely'
  act(() => coordinator.hint(['preferences']))
  await screen.findByText('Renamed remotely')
  expect(useEqProfilesStore.getState().activeProfileId).toBe('chosen')
  expect(useEqBandsStore.getState()).toBe(audioBefore)
  expect(commands).toEqual([])
  fireEvent.click(screen.getByRole('button', { name: 'Save' }))
  await waitFor(() => expect(savedConfig).toBeDefined())
  act(() => {
    advanceAuthorization()
    useEqProfilesStore.getState().resetForPrincipal()
  })
  await act(async () => { pending.reject(new Error('Offline old account')) })
  expect(useEqProfilesStore.getState().profiles).toEqual([])
})

it('refreshes sidebar baselines while preserving editor edits and exposes conflict', async () => {
  let label = 'Server section'
  const schema = () => ({ mediaType: 'music', sections: [{ id: 'custom', label, type: 'navigation', items: [
    { id: 'mine', type: 'page_link', label: 'My item', icon: 'home', config: { route: '/' } },
  ] }] })
  AXIOS_INSTANCE.defaults.adapter = async config => response(config, schema())
  const { coordinator, wrapper } = setup()
  const reader = renderHook(() => useSidebarConfig(), { wrapper })
  await waitFor(() => expect(useSidebarStore.getState().schemas.music.sections[0].label).toBe('Server section'))
  act(() => useSidebarStore.getState().setEditorOpen(true))
  render(<SidebarEditor />, { wrapper })
  fireEvent.click(screen.getByRole('button', { name: 'Remove item' }))
  label = 'Remote section'
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(useSidebarStore.getState().schemas.music.sections[0].label).toBe('Remote section'))
  expect(screen.getByRole('alert')).toHaveTextContent('sidebar changed elsewhere')
  expect(screen.getByText('Server section')).toBeInTheDocument()
  expect(screen.queryByText('My item')).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: 'Keep my edits' }))
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled()
  reader.unmount()
})

it('uses the account family for passkey deletion and refreshes every mounted observer without a socket', async () => {
  let keys = [{ publicId: 'passkey', name: 'Laptop', createdAt: '', lastUsedAt: null }]
  let getCount = 0
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'delete') keys = []
    else { getCount++; expect(config.signal).toBeDefined() }
    return response(config, { data: keys })
  }
  const { wrapper } = setup()
  const first = renderHook(() => ({ list: usePasskeyList(), remove: useDeletePasskey() }), { wrapper })
  const second = renderHook(() => usePasskeyList(), { wrapper })
  await waitFor(() => expect(first.result.current.list.data?.[0].name).toBe('Laptop'))
  await act(async () => { await first.result.current.remove.mutateAsync('passkey') })
  await waitFor(() => expect(first.result.current.list.data).toEqual([]))
  expect(second.result.current.data).toEqual([])
  expect(getCount).toBe(2)
})

it('acknowledges only submitted sidebar sections and keeps newer edits open after save', async () => {
  const pending = deferred<AxiosResponse>()
  let saveConfig: InternalAxiosRequestConfig | undefined
  const sections = [{ id: 'custom', label: 'Sections', type: 'navigation' as const, items: [
    { id: 'one', type: 'page_link' as const, label: 'One', icon: 'home', config: { route: '/' } },
    { id: 'two', type: 'page_link' as const, label: 'Two', icon: 'home', config: { route: '/' } },
  ] }]
  useSidebarStore.getState().setSchema('music', { mediaType: 'music', sections })
  useSidebarStore.getState().setEditorOpen(true)
  AXIOS_INSTANCE.defaults.adapter = config => { saveConfig = config; return pending.promise }
  render(<SidebarEditor />)
  fireEvent.click(screen.getAllByRole('button', { name: 'Remove item' })[0])
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  await waitFor(() => expect(saveConfig).toBeDefined())
  fireEvent.click(screen.getByRole('button', { name: 'Remove item' }))
  await act(async () => {
    pending.resolve(response(saveConfig!, { mediaType: 'music', sections: [{ ...sections[0], items: [sections[0].items[1]] }] }))
  })
  expect(useSidebarStore.getState().isEditorOpen).toBe(true)
  expect(screen.queryByText('Two')).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('purges unavailable sidebar baseline data and disables saving while preserving edited sections', async () => {
  const { AxiosError } = await import('axios')
  let denied = false
  const schema = { mediaType: 'music', sections: [{ id: 'custom', label: 'Retained draft', type: 'navigation', items: [
    { id: 'one', type: 'page_link', label: 'One', icon: 'home', config: { route: '/' } },
  ] }] }
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (denied) throw new AxiosError('Denied', 'ERR_BAD_REQUEST', config, undefined, { ...response(config, {}), status: 403 })
    return response(config, schema)
  }
  const { coordinator, wrapper } = setup()
  renderHook(() => useSidebarConfig(), { wrapper })
  await waitFor(() => expect(useSidebarStore.getState().schemas.music.sections[0].label).toBe('Retained draft'))
  act(() => useSidebarStore.getState().setEditorOpen(true))
  render(<SidebarEditor />, { wrapper })
  fireEvent.click(screen.getByRole('button', { name: 'Remove item' }))
  denied = true
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('unavailable'))
  expect(screen.getByText('Retained draft')).toBeInTheDocument()
  expect(screen.queryByText('One')).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled()
  expect(useSidebarStore.getState().schemas.music.sections[0].label).not.toBe('Retained draft')
})

it('refreshes generated user setting observers after set and reset without replaying either command', async () => {
  const { useUserSetting, useSetUserSetting, useResetUserSetting } = await import('@/features/settings/hooks/use-user-settings')
  let value = 'initial'
  const commands: string[] = []
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') { commands.push('set'); value = 'chosen' }
    if (config.method === 'delete') { commands.push('reset'); value = 'default' }
    const setting = { key: 'appearance', value, effectiveValue: value, defaultValue: 'default' }
    return response(config, { data: config.method === 'get' ? [setting] : setting })
  }
  const { coordinator, wrapper } = setup()
  const hook = renderHook(() => ({ setting: useUserSetting('appearance'), set: useSetUserSetting(), reset: useResetUserSetting() }), { wrapper })
  const other = renderHook(() => useUserSetting('appearance'), { wrapper })
  await waitFor(() => expect(hook.result.current.setting.data?.value).toBe('initial'))
  await act(async () => { await hook.result.current.set.mutateAsync({ key: 'appearance', data: { value: 'chosen' } }) })
  await waitFor(() => expect(other.result.current.data?.value).toBe('chosen'))
  await act(async () => { await hook.result.current.reset.mutateAsync({ key: 'appearance' }) })
  await waitFor(() => expect(other.result.current.data?.value).toBe('default'))
  value = 'remote'
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(other.result.current.data?.value).toBe('remote'))
  expect(commands).toEqual(['set', 'reset'])
})

it('refreshes theme and accent mirrors without feedback writes and holds remote snapshots during a local save', async () => {
  const { PreferenceSyncProvider } = await import('@/features/settings/hooks/use-preference-bootstrap')
  const { useAccentColor } = await import('@/features/settings/hooks/use-accent-color')
  const { useAuthStore } = await import('@/features/auth/stores/auth-store')
  const oldAuth = useAuthStore.getState()
  localStorage.setItem('baander-theme-mood', 'dark')
  const pending = deferred<AxiosResponse>()
  let pendingConfig: InternalAxiosRequestConfig | undefined
  let mood = 'warm'
  let color = 'blue'
  let themeReads = 0
  let puts = 0
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') {
      puts++
      pendingConfig = config
      color = JSON.parse(String(config.data)).color
      return pending.promise
    }
    if (config.url?.includes('theme-mood')) { themeReads++; return response(config, { mood }) }
    if (config.url?.includes('accent-color')) return response(config, { color })
    return response(config, { data: { version: 1, payload: {} } })
  }
  const { coordinator, wrapper } = setup()
  useAuthStore.setState({ isAuthenticated: true, user: { publicId: 'account', email: 'listener@baander.app', name: null, roles: [] } })
  disposals.push(() => useAuthStore.setState(oldAuth, true))
  render(<PreferenceSyncProvider><span>Content</span></PreferenceSyncProvider>, { wrapper })
  const setting = renderHook(() => useAccentColor(), { wrapper })
  await waitFor(() => expect(document.documentElement.getAttribute('data-theme')).toBe('warm'))
  expect(document.documentElement.getAttribute('data-accent')).toBe('blue')
  expect(puts).toBe(0)
  mood = 'cool'
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(document.documentElement.getAttribute('data-theme')).toBe('cool'))
  await act(async () => { await Promise.resolve() })
  const writes = vi.spyOn(Storage.prototype, 'setItem')
  const attributes = vi.spyOn(document.documentElement, 'setAttribute')
  const storageEvent = vi.fn()
  window.addEventListener('storage', storageEvent)
  disposals.push(() => window.removeEventListener('storage', storageEvent))
  const readsBeforeIdenticalSnapshot = themeReads
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(themeReads).toBeGreaterThan(readsBeforeIdenticalSnapshot))
  expect(writes).not.toHaveBeenCalled()
  expect(attributes).not.toHaveBeenCalled()
  expect(storageEvent).not.toHaveBeenCalled()
  writes.mockRestore()
  attributes.mockRestore()
  let save!: Promise<void>
  act(() => { save = setting.result.current.setColor('emerald') })
  await waitFor(() => expect(pendingConfig).toBeDefined())
  const before = themeReads
  act(() => coordinator.hint(['preferences']))
  await new Promise(resolve => setTimeout(resolve, 30))
  expect(themeReads).toBe(before)
  expect(document.documentElement.getAttribute('data-accent')).toBe('emerald')
  await act(async () => { pending.resolve(response(pendingConfig!, {})); await save })
  await waitFor(() => expect(themeReads).toBeGreaterThan(before))
  expect(localStorage.getItem('baander-accent-color')).toBe('emerald')
  expect(puts).toBe(1)
})

it('keeps opened preference history live while leaving rollback an explicit user command', async () => {
  const { PreferenceHistory } = await import('@/features/settings/components/PreferenceHistory')
  let historyVersion = 1
  let rollbacks = 0
  const historySignals: unknown[] = []
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'post') rollbacks++
    if (config.url?.endsWith('/history')) {
      historySignals.push(config.signal)
      return response(config, { data: { history: [{ version: historyVersion, created_at: '2026-10-30T12:00:00Z' }] } })
    }
    return response(config, { data: { version: 3, payload: { volume: 10 } } })
  }
  const { coordinator, wrapper } = setup()
  function History() {
    const sync = usePreferenceSync({
      baseUrl: '/api/user/player-preferences/', toPayload: (state: { volume: number }) => state,
      fromPayload: payload => ({ volume: Number(payload.volume) }), onRemoteUpdate: () => undefined,
    })
    return <PreferenceHistory fetchHistory={sync.fetchHistory} onRollback={sync.rollback} currentVersion={3} label="Player" />
  }
  render(<History />, { wrapper })
  expect(historySignals).toEqual([])
  fireEvent.click(screen.getByRole('button', { name: 'History' }))
  await screen.findByText(/v1/)
  historyVersion = 2
  act(() => coordinator.hint(['preferences']))
  await screen.findByText(/v2/)
  expect(historySignals.every(signal => signal instanceof AbortSignal)).toBe(true)
  expect(rollbacks).toBe(0)
  fireEvent.click(screen.getByRole('button', { name: 'Restore' }))
  await waitFor(() => expect(rollbacks).toBe(1))
})

it('retries a transient preference save and converges while healthy old-version GETs preserve the local edit', async () => {
  const { AxiosError } = await import('axios')
  let version = 1
  let volume = 10
  let attempts = 0
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') {
      attempts++
      if (attempts === 1) throw new AxiosError('Unavailable', 'ERR_BAD_RESPONSE', config, undefined, { ...response(config, {}), status: 503 })
      volume = JSON.parse(String(config.data)).payload.volume
      version++
    }
    return response(config, { data: { version, payload: { volume } } })
  }
  const { coordinator, wrapper } = setup()
  const apply = vi.fn()
  const hook = renderHook(() => usePreferenceSync({
    baseUrl: '/api/user/player-preferences/', toPayload: (state: { volume: number }) => state,
    fromPayload: payload => ({ volume: Number(payload.volume) }), onRemoteUpdate: apply,
  }), { wrapper })
  await waitFor(() => expect(apply).toHaveBeenCalledWith({ volume: 10 }, 1))
  act(() => hook.result.current.pushToServer({ volume: 80 }))
  await waitFor(() => expect(attempts).toBe(1))
  act(() => coordinator.hint(['preferences']))
  await act(async () => { expect(await hook.result.current.fetchFromServer()).toBe(false) })
  expect(apply).toHaveBeenCalledTimes(1)
  await waitFor(() => expect(attempts).toBe(2), { timeout: 2500 })
  await waitFor(() => expect(apply).toHaveBeenCalledWith({ volume: 80 }, 2), { timeout: 3500 })
})

it('replaces a failed-save retry with the latest edit and clears retries on ownership change', async () => {
  const { AxiosError } = await import('axios')
  vi.useFakeTimers({ now: Date.now() + 100_000 })
  let version = 1
  let volume = 10
  let fail = true
  const puts: number[] = []
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') {
      puts.push(JSON.parse(String(config.data)).payload.volume)
      if (fail) throw new AxiosError('Offline', 'ERR_NETWORK', config)
      volume = puts.at(-1)!
      version++
    }
    return response(config, { data: { version, payload: { volume } } })
  }
  const { wrapper } = setup()
  const hook = renderHook(() => usePreferenceSync({
    baseUrl: '/api/user/player-preferences/', toPayload: (state: { volume: number }) => state,
    fromPayload: payload => ({ volume: Number(payload.volume) }), onRemoteUpdate: () => undefined,
  }), { wrapper })
  await act(async () => { await vi.advanceTimersByTimeAsync(200) })
  act(() => hook.result.current.pushToServer({ volume: 80 }))
  await act(async () => { await vi.advanceTimersByTimeAsync(700) })
  expect(puts).toEqual([80])
  act(() => hook.result.current.pushToServer({ volume: 90 }))
  fail = false
  await act(async () => { await vi.advanceTimersByTimeAsync(700) })
  expect(puts).toEqual([80, 90])
  await act(async () => { await vi.advanceTimersByTimeAsync(30_000) })
  expect(puts).toEqual([80, 90])
  fail = true
  act(() => hook.result.current.pushToServer({ volume: 95 }))
  await act(async () => { await vi.advanceTimersByTimeAsync(700) })
  expect(puts).toEqual([80, 90, 95])
  expect(vi.getTimerCount()).toBe(1)
  act(() => advanceAuthorization())
  expect(vi.getTimerCount()).toBe(0)
  await act(async () => { await vi.advanceTimersByTimeAsync(30_000) })
  expect(puts).toEqual([80, 90, 95])
})

it('acknowledges a fetched preference conflict without degraded freshness or endless GET retries', async () => {
  const { AxiosError } = await import('axios')
  vi.useFakeTimers({ now: Date.now() + 100_000 })
  let version = 1
  let reads = 0
  let puts = 0
  AXIOS_INSTANCE.defaults.adapter = async config => {
    if (config.method === 'put') {
      puts++
      throw new AxiosError('Unavailable', 'ERR_BAD_RESPONSE', config, undefined, { ...response(config, {}), status: 503 })
    }
    reads++
    return response(config, { data: { version, payload: { volume: 10 } } })
  }
  const { coordinator, wrapper, resourceReadFailed } = setup()
  const hook = renderHook(() => usePreferenceSync({
    baseUrl: '/api/user/player-preferences/', toPayload: (state: { volume: number }) => state,
    fromPayload: payload => ({ volume: Number(payload.volume) }), onRemoteUpdate: () => undefined,
  }), { wrapper })
  await act(async () => { await vi.advanceTimersByTimeAsync(200) })
  act(() => hook.result.current.pushToServer({ volume: 80 }))
  await act(async () => { await vi.advanceTimersByTimeAsync(700) })
  version = 2
  act(() => coordinator.hint(['preferences']))
  await act(async () => { await vi.advanceTimersByTimeAsync(600) })
  expect(hook.result.current.conflict).toEqual({ type: 'conflict', serverVersion: 2 })
  const acknowledgedReads = reads
  await act(async () => { await vi.advanceTimersByTimeAsync(30_000) })
  expect(reads).toBe(acknowledgedReads)
  expect(puts).toBe(1)
  expect(resourceReadFailed).not.toHaveBeenCalled()
})

it('keeps identical decoded sidebar snapshots referentially stable across refreshes', async () => {
  let reads = 0
  const schema = { mediaType: 'music', sections: [{ id: 'custom', label: 'Same section', type: 'navigation', items: [] }] }
  AXIOS_INSTANCE.defaults.adapter = async config => {
    reads++
    return response(config, JSON.parse(JSON.stringify(schema)))
  }
  const { coordinator, wrapper } = setup()
  renderHook(() => useSidebarConfig(), { wrapper })
  await waitFor(() => expect(reads).toBe(1))
  const previous = useSidebarStore.getState().schemas.music
  const listener = vi.fn()
  const unsubscribe = useSidebarStore.subscribe(listener)
  disposals.push(unsubscribe)
  act(() => coordinator.hint(['preferences']))
  await waitFor(() => expect(reads).toBe(2))
  expect(useSidebarStore.getState().schemas.music).toBe(previous)
  expect(listener).not.toHaveBeenCalled()
})
