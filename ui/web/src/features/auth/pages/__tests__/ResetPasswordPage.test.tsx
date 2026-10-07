import { cleanup, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { LoginPage } from '../LoginPage'
import { ResetPasswordPage } from '../ResetPasswordPage'
import { httpError, renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

interface MockAuthState {
  isAuthenticated: boolean
  isLoading: boolean
  login: () => Promise<void>
  clearAuth: () => void
}

const authState = vi.hoisted(() => ({
  isAuthenticated: false,
  clearAuth: vi.fn(),
}))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: MockAuthState) => unknown) => selector({
    isAuthenticated: authState.isAuthenticated,
    isLoading: false,
    login: vi.fn(),
    clearAuth: () => {
      authState.isAuthenticated = false
      authState.clearAuth()
    },
  })),
}))

const mockRequest = vi.mocked(customInstance)
const TOKEN = '3c9f0e2a7b5d4c1e8f6a0b9d2c7e4f1a3c9f0e2a7b5d4c1e8f6a0b9d2c7e4f1a'

function mount(entry = `/reset-password#token=${TOKEN}`) {
  return renderAt(entry, {
    '/reset-password': <ResetPasswordPage />,
    '/login': <LoginPage />,
    '/forgot-password': <p>Forgot password page</p>,
  })
}

async function choose(password: string, confirmation = password) {
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('New password'), password)
  await user.type(screen.getByLabelText('Confirm new password'), confirmation)
  await user.click(screen.getByRole('button', { name: 'Set new password' }))
}

describe('ResetPasswordPage', () => {
  beforeEach(() => {
    mockRequest.mockReset()
    authState.isAuthenticated = false
    authState.clearAuth.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('takes the token from the link and removes it from the address', () => {
    mount()

    expect(screen.getByTestId('location')).toHaveTextContent(/^\/reset-password$/)
    expect(screen.getByLabelText('New password')).toBeInTheDocument()
  })

  it('sets the new password and confirms it on the login page', async () => {
    mockRequest.mockResolvedValue({ data: { message: 'Your password has been reset.' } })
    mount()

    await choose('a-new-password')

    expect(mockRequest).toHaveBeenCalledWith('/api/auth/password/reset', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ token: TOKEN, password: 'a-new-password' }),
    }))
    expect(await screen.findByRole('status')).toHaveTextContent('Your password has been reset. Log in with your new password.')
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/login$/)
    expect(authState.clearAuth).not.toHaveBeenCalled()
  })

  it('drops the local session after a reset, because the server ended it', async () => {
    authState.isAuthenticated = true
    mockRequest.mockResolvedValue({ data: { message: 'Your password has been reset.' } })
    mount()

    await choose('a-new-password')

    expect(await screen.findByRole('status')).toHaveTextContent('Your password has been reset.')
    expect(authState.clearAuth).toHaveBeenCalledOnce()
  })

  it('checks the length and the confirmation before calling the server', async () => {
    mount()

    await choose('short')
    expect(screen.getByRole('alert')).toHaveTextContent('Use 8 to 255 characters.')

    cleanup()
    mount()
    await choose('a-new-password', 'a-different-password')
    expect(screen.getByRole('alert')).toHaveTextContent('The passwords do not match.')

    expect(mockRequest).not.toHaveBeenCalled()
  })

  it('shows the generic invalid-link message when the server rejects the token', async () => {
    mockRequest.mockRejectedValue(httpError(400, 'This password reset link is invalid or has expired.'))
    mount()

    await choose('a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent('This password reset link is invalid or has expired.')
    expect(screen.getByRole('link', { name: 'Request a new link' })).toHaveAttribute('href', '/forgot-password')
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })

  it('waits for the Retry-After delay when rate limited', async () => {
    mockRequest.mockRejectedValue(httpError(429, 'Too many requests.', { 'retry-after': '1' }))
    mount()

    await choose('a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent('Try again in 1 second.')
    expect(screen.getByRole('button', { name: 'Set new password' })).toBeDisabled()
  })

  it('shows a validation message from the server', async () => {
    mockRequest.mockRejectedValue(httpError(422, 'The password is too common.'))
    mount()

    await choose('a-new-password')

    expect(await screen.findByRole('alert')).toHaveTextContent('The password is too common.')
  })

  it('explains a link without a token', () => {
    mount('/reset-password')

    expect(screen.getByRole('alert')).toHaveTextContent('This password reset link is invalid or has expired.')
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument()
  })
})
