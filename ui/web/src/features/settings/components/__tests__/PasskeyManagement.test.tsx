import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { render } from '../../../../../tests/test-utils'
import { PasskeyManagement } from '../PasskeyManagement'

const register = vi.fn<(name: string, stepUp: { currentPassword: string, totpCode?: string }) => Promise<boolean>>()

vi.mock('../../hooks/use-passkey-registration', () => ({
  usePasskeyRegistration: () => ({ register, loading: false, success: false }),
}))

vi.mock('../../hooks/use-passkey-list', () => ({
  usePasskeyList: () => ({
    isPending: false,
    data: [
      { publicId: 'passkey-yubikey', name: 'YubiKey', createdAt: '2026-10-01T09:00:00Z', lastUsedAt: null },
      { publicId: 'passkey-phone', name: 'ゆうきのスマホ', createdAt: '2026-10-02T09:00:00Z', lastUsedAt: null },
    ],
  }),
  useDeletePasskey: () => ({ isPending: false, mutate: vi.fn() }),
}))

function challengeRefusal() {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError('Request failed with status code 400', 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: 400, message: 'Invalid or expired challenge.' } },
    status: 400,
    statusText: 'Bad Request',
    headers: {},
    config,
  })
}

function signIn(totpEnabled: boolean) {
  useAuthStore.setState({
    isAuthenticated: true,
    user: { publicId: 'usr_member', name: 'Member', email: 'member@baander.app', roles: ['ROLE_USER'], totpEnabled },
  })
}

describe('PasskeyManagement', () => {
  afterEach(() => {
    cleanup()
    register.mockReset()
    useAuthStore.setState({ user: null, isAuthenticated: false })
  })

  it('renders each passkey name as content text in its own language', () => {
    render(<PasskeyManagement />)

    expect(screen.getByText('YubiKey')).toHaveAttribute('lang', 'en')
    expect(screen.getByText('ゆうきのスマホ')).toHaveAttribute('lang', 'ja')
  })

  it('asks for the password and code before it registers a passkey', async () => {
    signIn(true)
    register.mockResolvedValue(true)
    const user = userEvent.setup()
    render(<PasskeyManagement />)

    await user.click(screen.getByRole('button', { name: 'Add' }))
    await user.type(screen.getByLabelText('Passkey name'), 'Laptop')
    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.type(screen.getByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Register' }))

    expect(register).toHaveBeenCalledWith('Laptop', { currentPassword: 'current-password', totpCode: '123456' })
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Register new passkey' })).not.toBeInTheDocument())
  })

  it('starts the prompt over when the registration fails', async () => {
    signIn(false)
    register.mockRejectedValue(challengeRefusal())
    const user = userEvent.setup()
    render(<PasskeyManagement />)

    await user.click(screen.getByRole('button', { name: 'Add' }))
    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.click(screen.getByRole('button', { name: 'Register' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid or expired challenge.')
    expect(screen.getByLabelText('Current password')).toHaveValue('')
    expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument()
    expect(register).toHaveBeenCalledWith('Passkey', { currentPassword: 'current-password' })
  })
})
