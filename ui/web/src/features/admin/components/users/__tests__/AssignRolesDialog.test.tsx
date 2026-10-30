import { act, cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { advanceAuthorization } from '@/shared/live/ownership'
import type { AdminUser } from '../../../api/user-admin-api'
import { render } from '../../../../../../tests/test-utils'
import { AssignRolesDialog } from '../AssignRolesDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post: vi.fn() } }))

const initialAuth = useAuthStore.getState()
const post = vi.mocked(AXIOS_INSTANCE.post)
const target: AdminUser = {
  publicId: 'UserPublicIdentity01',
  email: 'listener@baander.app',
  name: 'Listener',
  roles: ['ROLE_USER'],
  disabled: false,
  createdAt: '2026-10-01T00:00:00Z',
}

function signIn(roles: string[]) {
  useAuthStore.setState({ user: {
    publicId: 'AdminPublicIdentity01',
    email: 'admin@baander.app',
    name: 'Admin',
    roles,
  } })
}

function mount(user = target) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })
  const onOpenChange = vi.fn()
  const content = (snapshot: AdminUser) => (
    <QueryClientProvider client={client}>
      <AssignRolesDialog user={snapshot} open onOpenChange={onOpenChange} />
    </QueryClientProvider>
  )
  const view = render(content(user))
  return { onOpenChange, update: (snapshot: AdminUser) => view.rerender(content(snapshot)) }
}

describe('AssignRolesDialog live updates', () => {
  beforeEach(() => {
    signIn(['ROLE_SUPER_ADMIN'])
    post.mockReset()
    post.mockResolvedValue({ data: {} })
  })

  afterEach(() => {
    cleanup()
    useAuthStore.setState(initialAuth, true)
  })

  it('preserves chosen roles and blocks saving when another administrator changes the baseline', async () => {
    const user = userEvent.setup()
    const { update } = mount()
    await user.click(screen.getByRole('checkbox', { name: 'ADMIN' }))
    update({ ...target, roles: ['ROLE_USER', 'ROLE_SUPER_ADMIN'] })
    expect(screen.getByRole('checkbox', { name: 'ADMIN' })).toBeChecked()
    expect(screen.getByRole('button', { name: 'Save Roles' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Keep my changes' }))
    await user.click(screen.getByRole('button', { name: 'Save Roles' }))
    await waitFor(() => expect(post).toHaveBeenCalledWith(`/api/admin/users/${target.publicId}/roles`, {
      roles: ['ROLE_USER', 'ROLE_ADMIN'],
    }))
  })

  it('removes untouched server roles and cannot rehydrate them after administrator role loss', async () => {
    const snapshot = { ...target, roles: ['ROLE_USER', 'ROLE_SUPER_ADMIN'] }
    const { update } = mount(snapshot)
    expect(screen.getByRole('checkbox', { name: /SUPER_ADMIN/ })).toBeChecked()
    act(() => {
      advanceAuthorization()
      signIn(['ROLE_ADMIN'])
    })
    update({ ...snapshot, name: 'Updated elsewhere' })
    expect(screen.getByRole('checkbox', { name: /SUPER_ADMIN/ })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ADMIN' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Save Roles' })).toBeDisabled()
    expect(post).not.toHaveBeenCalled()
  })

  it('retains newer role choices after an earlier save completes', async () => {
    let finish: (() => void) | undefined
    post.mockImplementationOnce(() => new Promise(resolve => {
      finish = () => resolve({ data: {} })
    }))
    const user = userEvent.setup()
    const { onOpenChange } = mount()
    await user.click(screen.getByRole('checkbox', { name: 'ADMIN' }))
    await user.click(screen.getByRole('button', { name: 'Save Roles' }))
    await waitFor(() => expect(finish).toBeDefined())
    await user.click(screen.getByRole('checkbox', { name: 'ADMIN' }))
    await act(async () => { finish!() })
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save Roles' })).toBeEnabled())
    expect(screen.getByRole('checkbox', { name: 'ADMIN' })).not.toBeChecked()
    expect(onOpenChange).not.toHaveBeenCalled()
    await user.click(screen.getByRole('button', { name: 'Save Roles' }))
    await waitFor(() => expect(post).toHaveBeenLastCalledWith(`/api/admin/users/${target.publicId}/roles`, {
      roles: ['ROLE_USER'],
    }))
    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  })

  it('ignores the close callback of a role mutation completed after authorization changes', async () => {
    let finish: (() => void) | undefined
    post.mockImplementation(() => new Promise(resolve => {
      finish = () => resolve({ data: {} })
    }))
    const user = userEvent.setup()
    const { onOpenChange } = mount()
    await user.click(screen.getByRole('checkbox', { name: 'ADMIN' }))
    await user.click(screen.getByRole('button', { name: 'Save Roles' }))
    await waitFor(() => expect(finish).toBeDefined())
    await act(async () => {
      advanceAuthorization()
      finish!()
    })
    expect(onOpenChange).not.toHaveBeenCalled()
  })
})
