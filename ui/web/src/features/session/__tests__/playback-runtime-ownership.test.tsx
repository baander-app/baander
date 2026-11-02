import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { advanceAuthorization, synchronizeOwnership } from '@/shared/live/ownership'
import { PlaybackSessionRuntime } from '../components/PlaybackSessionRuntime'

const runtime = vi.hoisted(() => ({ start: vi.fn(), stop: vi.fn(), unregister: vi.fn(), register: vi.fn() }))
vi.mock('@/features/auth/stores/auth-store', () => ({ useAuthStore: (select: (state: { principalSessionId: string; user: { publicId: string } }) => unknown) => select({ principalSessionId: 'login-session', user: { publicId: 'listener' } }) }))
vi.mock('../services/playback-session-coordinator', () => ({
  PlaybackSessionCoordinator: class { start() { runtime.start(); return runtime.stop } },
  registerPlaybackCoordinator: () => { runtime.register(); return runtime.unregister },
}))
afterEach(() => { cleanup(); vi.clearAllMocks(); synchronizeOwnership(null) })

describe('playback runtime ownership', () => {
  it('replaces its coordinator after same-principal authorization recovery', () => {
    synchronizeOwnership('login-session')
    render(<QueryClientProvider client={new QueryClient()}><PlaybackSessionRuntime /></QueryClientProvider>)
    expect(runtime.start).toHaveBeenCalledTimes(1)
    act(() => { advanceAuthorization() })
    expect(runtime.stop).toHaveBeenCalledTimes(1)
    expect(runtime.unregister).toHaveBeenCalledTimes(1)
    expect(runtime.start).toHaveBeenCalledTimes(2)
  })
})
