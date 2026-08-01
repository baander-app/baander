import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'

vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: {},
  customInstance: vi.fn().mockRejectedValue(new Error('API not available in tests')),
}))

vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  clearDpopKeyPair: vi.fn(),
}))

vi.mock('@/features/player/services/service-worker-bridge', () => ({
  postTokenToWorker: vi.fn().mockResolvedValue(undefined),
}))

beforeEach(() => {
  useAuthStore.setState({
    accessToken: null,
    refreshToken: null,
    user: null,
    isAuthenticated: false,
    isLoading: false,
  })
})

describe('auth-store', () => {
  it('clearAuth resets all state', () => {
    useAuthStore.setState({
      accessToken: 'token',
      refreshToken: 'refresh',
      user: { uuid: '1', email: 'a@b.com', publicId: 'p1', name: null, roles: ['ROLE_USER'] },
      isAuthenticated: true,
    })

    useAuthStore.getState().clearAuth()

    const state = useAuthStore.getState()
    expect(state.isAuthenticated).toBe(false)
    expect(state.accessToken).toBeNull()
    expect(state.user).toBeNull()
  })

  it('setTokens sets auth state', () => {
    useAuthStore.getState().setTokens('access-123', 'refresh-456')

    const state = useAuthStore.getState()
    expect(state.isAuthenticated).toBe(true)
    expect(state.accessToken).toBe('access-123')
    expect(state.refreshToken).toBe('refresh-456')
  })

  it('login rejects when API is not available and not in mock mode', async () => {
    await expect(
      useAuthStore.getState().login('user@example.com', 'password'),
    ).rejects.toThrow()

    expect(useAuthStore.getState().isAuthenticated).toBe(false)
  })
})
