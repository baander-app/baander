import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { CreateUserDialog } from '../CreateUserDialog'

vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { post: vi.fn() } }))

const mockPost = vi.mocked(AXIOS_INSTANCE.post)

function mount(canAssignRoles: boolean) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>
        <CreateUserDialog open onOpenChange={() => {}} canAssignRoles={canAssignRoles} />
      </ThemeProvider>
    </QueryClientProvider>,
  )
}

describe('create user dialog roles', () => {
  beforeEach(() => {
    mockPost.mockReset()
    mockPost.mockResolvedValue({ data: { data: {} } })
  })

  afterEach(() => {
    cleanup()
  })

  it('offers the role choice to a super admin', () => {
    mount(true)

    expect(screen.getByRole('combobox', { name: 'Role' })).toBeInTheDocument()
  })

  it('creates an ordinary user without a role choice for an admin', async () => {
    const user = userEvent.setup()
    mount(false)

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()

    const [email, name, password] = [
      document.querySelector<HTMLInputElement>('input[type="email"]'),
      document.querySelectorAll<HTMLInputElement>('input:not([type])')[0],
      document.querySelector<HTMLInputElement>('input[type="password"]'),
    ]
    await user.type(email!, 'created@baander.app')
    await user.type(name!, 'Created User')
    await user.type(password!, 'securePassword123')
    await user.click(screen.getByRole('button', { name: 'Create' }))

    await waitFor(() => expect(mockPost).toHaveBeenCalledWith('/api/admin/users', {
      email: 'created@baander.app',
      name: 'Created User',
      password: 'securePassword123',
      roles: ['ROLE_USER'],
    }))
  })
})
