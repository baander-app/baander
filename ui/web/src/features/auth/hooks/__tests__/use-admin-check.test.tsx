import { act, cleanup, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/features/player/services/service-worker-bridge', () => ({
  postTokenToWorker: vi.fn().mockResolvedValue(undefined),
}))

import { useAuthStore } from '../../stores/auth-store'
import { useAdminCheck, useIsAdmin } from '../use-admin-check'

const initialState = useAuthStore.getState()
const user = {
  uuid: '0198d4d2-7600-7000-8000-000000000001',
  publicId: 'AdminSelectorFixture1',
  email: 'selector@baander.app',
  name: 'Selector user',
  roles: ['ROLE_USER'],
}

beforeEach(() => {
  useAuthStore.setState({ user })
})

afterEach(() => {
  cleanup()
  useAuthStore.setState(initialState, true)
})

describe('admin selectors', () => {
  it.each([
    ['ROLE_USER', false],
    ['ROLE_ADMIN', true],
    ['ROLE_SUPER_ADMIN', true],
  ])('preserves existing role semantics for %s', (role, expected) => {
    useAuthStore.setState({ user: { ...user, roles: [role] } })
    const { result } = renderHook(() => ({ boolean: useIsAdmin(), full: useAdminCheck() }))

    expect(result.current.boolean).toBe(expected)
    expect(result.current.full).toEqual({
      isAdmin: expected,
      isSuperAdmin: role === 'ROLE_SUPER_ADMIN',
      roles: [role],
    })
  })

  it('does not rerender for unrelated user or token updates, but follows permissions', () => {
    let renders = 0
    const { result } = renderHook(() => {
      renders += 1
      return useIsAdmin()
    })
    const initialRenders = renders

    act(() => {
      useAuthStore.setState({ user: { ...user, name: 'Updated name' } })
      useAuthStore.setState({ accessToken: 'test-access', refreshToken: 'test-refresh' })
      useAuthStore.setState({ user: { ...user, roles: ['ROLE_USER', 'ROLE_OTHER'] } })
    })
    expect(result.current).toBe(false)
    expect(renders).toBe(initialRenders)

    act(() => {
      useAuthStore.setState({ user: { ...user, roles: ['ROLE_ADMIN'] } })
    })
    expect(result.current).toBe(true)
    expect(renders).toBe(initialRenders + 1)

    act(() => {
      useAuthStore.setState({ user: { ...user, roles: ['ROLE_SUPER_ADMIN'] } })
    })
    expect(renders).toBe(initialRenders + 1)

    act(() => {
      useAuthStore.setState({ user: null })
    })
    expect(result.current).toBe(false)
    expect(renders).toBe(initialRenders + 2)
  })
})
