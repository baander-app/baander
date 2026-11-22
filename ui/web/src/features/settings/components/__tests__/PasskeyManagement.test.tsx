import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { render } from '../../../../../tests/test-utils'
import { PasskeyManagement } from '../PasskeyManagement'

type StepUp = { currentPassword: string, totpCode?: string }

const register = vi.fn<(name: string, stepUp: StepUp) => Promise<boolean>>()
const deletePasskey = vi.fn<(deletion: { publicId: string, stepUp: StepUp }) => Promise<unknown>>()

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
  useDeletePasskey: () => ({ isPending: false, mutateAsync: deletePasskey }),
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

function stepUpRefusal() {
  const config = { headers: new AxiosHeaders() }

  return new AxiosError('Request failed with status code 422', 'ERR_BAD_REQUEST', config, undefined, {
    data: { error: { code: 422, message: 'The password or code is incorrect.', details: { reason: 'step_up_failed' } } },
    status: 422,
    statusText: 'Unprocessable Content',
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
    deletePasskey.mockReset()
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
    expect(screen.getByRole('dialog', { name: 'Register new passkey' })).toHaveTextContent('This signs you out on your other devices and apps.')
    await user.type(screen.getByLabelText('Passkey name'), 'Laptop')
    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.type(screen.getByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Register' }))

    expect(register).toHaveBeenCalledWith('Laptop', { currentPassword: 'current-password', totpCode: '123456' })
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Register new passkey' })).not.toBeInTheDocument())
  })

  it('asks for the password and code before it deletes a passkey', async () => {
    signIn(true)
    deletePasskey.mockResolvedValue({ data: { message: 'Passkey deleted successfully.' } })
    const user = userEvent.setup()
    render(<PasskeyManagement />)

    await user.click(screen.getByRole('button', { name: 'Delete YubiKey' }))
    expect(screen.getByRole('dialog', { name: 'Delete passkey' })).toHaveTextContent('This signs you out on your other devices and apps.')
    await user.type(screen.getByLabelText('Current password'), 'current-password')
    await user.type(screen.getByLabelText('Authentication code'), '123456')
    await user.click(screen.getByRole('button', { name: 'Delete' }))

    expect(deletePasskey).toHaveBeenCalledWith({
      publicId: 'passkey-yubikey',
      stepUp: { currentPassword: 'current-password', totpCode: '123456' },
    })
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Delete passkey' })).not.toBeInTheDocument())
  })

  it('keeps the passkey dialog open when the password is wrong', async () => {
    signIn(false)
    deletePasskey.mockRejectedValue(stepUpRefusal())
    const user = userEvent.setup()
    render(<PasskeyManagement />)

    await user.click(screen.getByRole('button', { name: 'Delete YubiKey' }))
    await user.type(screen.getByLabelText('Current password'), 'not-the-password')
    await user.click(screen.getByRole('button', { name: 'Delete' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Password or code is incorrect')
    expect(screen.getByRole('dialog', { name: 'Delete passkey' })).toBeInTheDocument()
    expect(deletePasskey).toHaveBeenCalledWith({ publicId: 'passkey-yubikey', stepUp: { currentPassword: 'not-the-password' } })
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
