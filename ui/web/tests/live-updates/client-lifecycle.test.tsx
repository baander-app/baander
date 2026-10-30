import { StrictMode, useEffect } from 'react'
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import { QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { advanceAuthorization, captureOwnership, guardMutationCallbacks, setAuthorityPending, synchronizeOwnership } from '@/shared/live/ownership'
import { LiveRecovery } from '@/shared/live/recovery'
import { authenticatedTransport, type SharedLiveTransport, type LiveTransportListener } from '@/shared/live/transport'
import { AuthenticatedClientProvider } from '@/shared/live/provider'
import { usePaletteStore } from '@/features/visualizer/stores/palette-store'
import { useAuthStore } from '@/features/auth/stores/auth-store'

vi.mock('@/shared/api-client/gen/endpoints', async importOriginal => ({
  ...await importOriginal<typeof import('@/shared/api-client/gen/endpoints')>(),
  getLiveRecovery: vi.fn(async () => authority()),
}))

const transportState = vi.hoisted(() => ({ leases: 0, listeners: new Set<LiveTransportListener>() }))
vi.mock('@/shared/live/transport', () => ({
  authenticatedTransport: {
    retain: () => {
      transportState.leases++
      return () => { transportState.leases-- }
    },
    subscribe: (listener: LiveTransportListener) => {
      transportState.listeners.add(listener)
      return () => { transportState.listeners.delete(listener) }
    },
    isConnected: () => false,
  },
}))

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

afterEach(() => {
  cleanup()
  setAuthorityPending(false)
  useAuthStore.setState({ isAuthenticated: false, principalSessionId: null, user: null })
  synchronizeOwnership(null)
})

describe('authenticated query ownership', () => {
  it('fences pending query and mutation callbacks after account replacement', async () => {
    synchronizeOwnership('account-a')
    const client = new OwnedQueryClient()
    client.attach()
    const query = deferred<string>()
    const mutation = deferred<string>()
    const success = vi.fn()
    const error = vi.fn()
    const settled = vi.fn()
    function Consumer() {
      useQuery({ queryKey: ['protected'], queryFn: () => query.promise })
      const { mutate } = useMutation({ mutationFn: () => mutation.promise, onSuccess: success, onError: error, onSettled: settled })
      useEffect(() => { mutate() }, [mutate])
      return null
    }
    render(<QueryClientProvider client={client}><Consumer /></QueryClientProvider>)
    await waitFor(() => expect(client.isMutating()).toBe(1))
    synchronizeOwnership('account-b')
    query.resolve('old-account')
    mutation.resolve('old-account')
    await act(async () => { await mutation.promise })
    expect(success).not.toHaveBeenCalled()
    expect(error).not.toHaveBeenCalled()
    expect(settled).not.toHaveBeenCalled()
    expect(client.getQueryData(['protected'])).toBeUndefined()
    client.dispose()
  })

  it('removes already rendered protected data from mounted observers on access reset', async () => {
    synchronizeOwnership('account-a')
    const client = new OwnedQueryClient()
    client.attach()
    function Reader() {
      const query = useQuery({ queryKey: ['visible-protected'], queryFn: async () => 'protected data' })
      return <span>{query.data ?? 'unavailable'}</span>
    }
    render(<QueryClientProvider client={client}><Reader /></QueryClientProvider>)
    await screen.findByText('protected data')
    act(() => {
      setAuthorityPending(true)
      advanceAuthorization()
    })
    await waitFor(() => expect(screen.queryByText('protected data')).not.toBeInTheDocument())
    expect(client.getQueryData(['visible-protected'])).toBeUndefined()
    client.dispose()
  })

  it('rejects a mutation launched by an old observer before optimistic callbacks run', async () => {
    synchronizeOwnership('account-a')
    const client = new OwnedQueryClient()
    client.attach()
    const optimistic = vi.fn()
    const error = vi.fn()
    const write = vi.fn(async () => 'saved')
    let execute: (() => void) | undefined
    function Writer() {
      const { mutate } = useMutation({ mutationFn: write, onMutate: optimistic, onError: error })
      execute = () => mutate()
      return null
    }
    render(<QueryClientProvider client={client}><Writer /></QueryClientProvider>)
    synchronizeOwnership('account-b')
    await act(async () => { execute?.() })
    expect(optimistic).not.toHaveBeenCalled()
    expect(write).not.toHaveBeenCalled()
    expect(error).not.toHaveBeenCalled()
    client.dispose()
  })

  it('purges inactive caches on access loss and guards per-call continuations', () => {
    synchronizeOwnership('account-a')
    const client = new OwnedQueryClient()
    client.attach()
    client.setQueryData(['inactive', 'protected'], 'secret')
    const callback = vi.fn()
    const callbacks = guardMutationCallbacks({ onSuccess: callback }, captureOwnership())
    advanceAuthorization()
    callbacks.onSuccess()
    expect(callback).not.toHaveBeenCalled()
    expect(client.getQueryData(['inactive', 'protected'])).toBeUndefined()
    client.dispose()
  })
})

describe('live recovery authority', () => {
  it('preserves ten loaded pages and mirrors through outage and unchanged-authority recovery', async () => {
    synchronizeOwnership('account-a')
    const client = new OwnedQueryClient()
    client.attach()
    const pages = { pages: Array.from({ length: 10 }, (_, index) => [`song-${index}`]), pageParams: Array.from({ length: 10 }, (_, index) => index) }
    client.setQueryData(['catalog', 'infinite'], pages)
    let listener: LiveTransportListener | undefined
    const transport = {
      subscribe: (next: LiveTransportListener) => {
        listener = next
        return () => { listener = undefined }
      },
      retain: () => () => undefined,
      isConnected: () => false,
    } as unknown as SharedLiveTransport
    const sameAuthority = { ...authority(), authorizationFingerprint: 'a'.repeat(64) }
    const load = vi.fn().mockRejectedValueOnce({ response: { status: 503 } }).mockResolvedValueOnce(sameAuthority)
    const purge = vi.fn()
    const recovery = new LiveRecovery(transport, purge, load)
    const stop = recovery.start()
    listener?.onMessage?.(wire('live.ready', ['catalog']))
    listener?.onClose?.()
    await waitFor(() => expect(recovery.getSnapshot().status).toBe('degraded'))
    expect(client.getQueryData(['catalog', 'infinite'])).toBe(pages)
    expect(purge).not.toHaveBeenCalled()
    expect(recovery.getSnapshot().authorityReady).toBe(false)
    listener?.onOpen?.()
    await waitFor(() => expect(recovery.getSnapshot().authorityReady).toBe(true))
    expect(client.getQueryData(['catalog', 'infinite'])).toBe(pages)
    expect(purge).not.toHaveBeenCalled()
    expect(recovery.getSnapshot().status).toBe('recovering')
    stop()
    client.dispose()
  })

  it.each(['denied', 'changed fingerprint'] as const)('purges on %s and rejects a late protected read', async mode => {
    synchronizeOwnership('account-a')
    const client = new OwnedQueryClient()
    client.attach()
    const pendingRead = deferred<string>()
    const read = client.fetchQuery({ queryKey: ['pending-protected'], queryFn: () => pendingRead.promise })
    const readResult = read.catch(() => undefined)
    await act(async () => { await Promise.resolve() })
    client.setQueryData(['retained-protected'], 'protected snapshot')
    let listener: LiveTransportListener | undefined
    const transport = {
      subscribe: (next: LiveTransportListener) => {
        listener = next
        return () => { listener = undefined }
      },
      retain: () => () => undefined,
      isConnected: () => false,
    } as unknown as SharedLiveTransport
    const load = vi.fn()
    if (mode === 'denied') load.mockRejectedValue({ response: { status: 403 } })
    else load.mockResolvedValue(authority())
    const purge = vi.fn()
    const recovery = new LiveRecovery(transport, purge, load)
    const stop = recovery.start()
    listener?.onMessage?.(wire('live.ready', ['catalog']))
    listener?.onClose?.()
    await waitFor(() => expect(purge).toHaveBeenCalledOnce())
    pendingRead.resolve('late protected response')
    await readResult
    expect(client.getQueryData(['retained-protected'])).toBeUndefined()
    expect(client.getQueryData(['pending-protected'])).toBeUndefined()
    expect(recovery.getSnapshot().authorityReady).toBe(mode !== 'denied')
    stop()
    client.dispose()
  })

  it('retains dirty hints during permission recovery and rejects replaced-principal messages', async () => {
    synchronizeOwnership('account-a')
    const pending = deferred<ReturnType<typeof authority>>()
    const listeners = new Set<LiveTransportListener>()
    const transport = {
      subscribe: (listener: LiveTransportListener) => {
        listeners.add(listener)
        return () => { listeners.delete(listener) }
      },
      retain: () => () => undefined,
      isConnected: () => false,
    } as unknown as SharedLiveTransport
    const recovery = new LiveRecovery(transport, vi.fn(), () => pending.promise)
    const events = vi.fn()
    recovery.subscribeEvents(events)
    const stop = recovery.start()
    const send = (message: unknown) => listeners.forEach(listener => listener.onMessage?.(message))
    send(wire('live.ready', ['catalog']))
    send({ ...wire('live.reset', ['catalog'], '2'), authorizationFingerprint: 'b'.repeat(64) })
    send({ ...wire('live.changed', ['catalog'], '2'), authorizationFingerprint: 'b'.repeat(64) })
    expect(events).toHaveBeenCalledWith({ type: 'dirty', families: ['catalog'] })
    const before = recovery.getSnapshot()
    synchronizeOwnership('account-b')
    send({ ...wire('live.reset', ['admin-users'], '3'), authorizationFingerprint: 'c'.repeat(64) })
    expect(recovery.getSnapshot()).toBe(before)
    pending.resolve(authority())
    await act(async () => { await pending.promise })
    expect(recovery.getSnapshot()).toBe(before)
    stop()
  })

  it('treats reset families as purge hints and waits for current recovery authority', async () => {
    synchronizeOwnership('account-a')
    const pending = deferred<ReturnType<typeof authority>>()
    const listeners = new Set<{ onMessage?: (message: unknown) => void }>()
    const transport = {
      subscribe: (listener: { onMessage?: (message: unknown) => void }) => {
        listeners.add(listener)
        return () => { listeners.delete(listener) }
      },
      retain: () => () => undefined,
      isConnected: () => false,
    } as unknown as SharedLiveTransport
    const purge = vi.fn()
    const recovery = new LiveRecovery(transport, purge, () => pending.promise)
    const stop = recovery.start()
    const send = (message: unknown) => listeners.forEach(listener => listener.onMessage?.(message))
    send(wire('live.ready', ['catalog', 'admin-users']))
    expect(recovery.getSnapshot().families).toEqual(['catalog', 'admin-users'])
    send(wire('live.reset', ['catalog', 'admin-users'], '2'))
    expect(purge).toHaveBeenCalled()
    expect(recovery.getSnapshot().families).toEqual([])
    expect(recovery.getSnapshot().status).toBe('recovering')
    pending.resolve(authority())
    await waitFor(() => expect(recovery.getSnapshot().families).toEqual(['catalog']))
    expect(recovery.getSnapshot().authorizationFingerprint).toBe('b'.repeat(64))
    stop()
  })
})

function authority() {
  return {
    version: 1 as const,
    families: ['catalog' as const],
    authorizationFingerprint: 'b'.repeat(64),
    health: { state: 'current' as const, oldestPendingAgeMs: null },
  }
}

function wire(type: string, families: string[], authorizationGeneration = '1') {
  return {
    version: 1,
    type,
    subscriptionId: 'a'.repeat(32),
    authorizationGeneration,
    authorizationFingerprint: 'a'.repeat(64),
    families,
    health: { state: 'current', oldestPendingAgeMs: null },
  }
}

describe('authenticated provider lifecycle', () => {
  it('loads mounted queries and refreshes hints after StrictMode effect replay', async () => {
    useAuthStore.setState({ isAuthenticated: true, principalSessionId: 'strict-query-account' })
    const read = vi.fn().mockResolvedValueOnce(0).mockResolvedValueOnce(1)
    function Notifications() {
      const query = useQuery({ queryKey: ['notification-unread-count'], queryFn: read })
      return <span>{query.data === undefined ? 'Loading notifications' : `${query.data} unread`}</span>
    }
    const view = render(<StrictMode><AuthenticatedClientProvider freshnessEnabled><Notifications /></AuthenticatedClientProvider></StrictMode>)
    act(() => transportState.listeners.forEach(listener => listener.onMessage?.(wire('live.ready', ['notifications']))))
    await screen.findByText('0 unread')
    act(() => transportState.listeners.forEach(listener => listener.onMessage?.(wire('live.changed', ['notifications']))))
    await screen.findByText('1 unread')
    expect(read).toHaveBeenCalledTimes(2)
    view.unmount()
    expect(transportState.leases).toBe(0)
    expect(transportState.listeners.size).toBe(0)
  })

  it('retains one shared connection without a listening session under StrictMode', () => {
    useAuthStore.setState({ isAuthenticated: true, principalSessionId: 'strict-account' })
    const view = render(<StrictMode><AuthenticatedClientProvider><span>signed in</span></AuthenticatedClientProvider></StrictMode>)
    expect(transportState.leases).toBe(1)
    expect(transportState.listeners.size).toBe(1)
    view.unmount()
    expect(transportState.leases).toBe(0)
    expect(transportState.listeners.size).toBe(0)
  })

  it('preserves player lifetime on rotation and access reset, replaces observers on principal switch', async () => {
    useAuthStore.setState({ isAuthenticated: true, principalSessionId: 'principal-a' })
    const mounts = vi.fn()
    const clients: OwnedQueryClient[] = []
    function Player() {
      const client = useQueryClient()
      useEffect(() => {
        mounts()
        clients.push(client as OwnedQueryClient)
      }, [client])
      return <span>player</span>
    }
    const view = render(<AuthenticatedClientProvider><Player /></AuthenticatedClientProvider>)
    const client = clients[0]
    client.setQueryData(['inactive', 'protected'], 'secret')
    const palette = { primary: '#111111', secondary: '#222222', accent: '#333333', background: '#000000', isDark: true }
    const token = usePaletteStore.getState().beginExtraction('album')
    usePaletteStore.getState().finishExtraction('album', token, palette)
    act(() => useAuthStore.setState({ accessToken: 'rotated-token' }))
    expect(usePaletteStore.getState().getPalette('album')).toEqual(palette)
    expect(mounts).toHaveBeenCalledTimes(1)
    expect(client.getQueryData(['inactive', 'protected'])).toBe('secret')
    act(() => {
      transportState.listeners.forEach(listener => listener.onMessage?.(wire('live.ready', ['catalog', 'admin-users'])))
      transportState.listeners.forEach(listener => listener.onMessage?.(wire('live.reset', ['catalog', 'admin-users'], '2')))
    })
    expect(client.getQueryData(['inactive', 'protected'])).toBeUndefined()
    expect(usePaletteStore.getState().getPalette('album')).toBeNull()
    const replacementToken = usePaletteStore.getState().beginExtraction('album')
    usePaletteStore.getState().finishExtraction('album', replacementToken, palette)
    expect(mounts).toHaveBeenCalledTimes(1)
    act(() => useAuthStore.setState({ principalSessionId: 'principal-b' }))
    expect(mounts).toHaveBeenCalledTimes(2)
    expect(usePaletteStore.getState().getPalette('album')).toBeNull()
    expect(clients[1]).not.toBe(client)
    expect(clients[1].getQueryData(['inactive', 'protected'])).toBeUndefined()
    view.unmount()
    expect(transportState.leases).toBe(0)
    expect(authenticatedTransport.isConnected()).toBe(false)
  })
})
