import { cleanup, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { ForgotPasswordPage } from '../ForgotPasswordPage'
import { httpError, renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: { isAuthenticated: boolean }) => unknown) => selector({ isAuthenticated: false })),
}))

const mockRequest = vi.mocked(customInstance)

function mount() {
  return renderAt('/forgot-password', {
    '/forgot-password': <ForgotPasswordPage />,
    '/login': <p>Login page</p>,
  })
}

async function submit(email: string) {
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('Email'), email)
  await user.click(screen.getByRole('button', { name: 'Send reset link' }))
}

describe('ForgotPasswordPage', () => {
  beforeEach(() => {
    mockRequest.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('sends the address and shows the same confirmation whatever the outcome', async () => {
    mockRequest.mockResolvedValue({ data: { message: 'If the email exists, a password reset link has been sent.' } })
    mount()

    await submit('listener@baander.app')

    expect(await screen.findByRole('status')).toHaveTextContent('If that address belongs to an account, a reset link is on its way.')
    expect(mockRequest).toHaveBeenCalledWith('/api/auth/password/reset-request', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ email: 'listener@baander.app' }),
    }))
    expect(screen.queryByLabelText('Email')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to log in' })).toHaveAttribute('href', '/login')
  })

  it('waits for the Retry-After delay when rate limited', async () => {
    mockRequest.mockRejectedValue(httpError(429, 'Too many requests.', { 'retry-after': '30' }))
    mount()

    await submit('listener@baander.app')

    expect(await screen.findByRole('alert')).toHaveTextContent('Try again in 30 seconds.')
    expect(screen.getByRole('button', { name: 'Send reset link' })).toBeDisabled()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('shows the server message for a rejected address', async () => {
    mockRequest.mockRejectedValue(httpError(400, 'Invalid email address.'))
    mount()

    await submit('listener@baander.app')

    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid email address.')
    expect(screen.getByRole('button', { name: 'Send reset link' })).toBeEnabled()
  })
})
