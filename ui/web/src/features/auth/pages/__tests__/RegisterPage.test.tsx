import { cleanup, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import { RegisterPage } from '../RegisterPage'
import { httpError, renderAt } from './auth-page-test-utils'

vi.mock('@/shared/api-client/axios-instance', () => ({ customInstance: vi.fn() }))

const { register } = vi.hoisted(() => ({
  register: vi.fn<(email: string, password: string, name?: string) => Promise<void>>(),
}))

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: vi.fn((selector: (state: object) => unknown) => selector({
    isAuthenticated: false,
    isLoading: false,
    register,
  })),
}))

const mockRequest = vi.mocked(customInstance)

const CLOSED_MESSAGE = 'This server is not open for sign-ups. Ask its administrator to create an account for you.'

function answerRegistrationState(open: boolean) {
  mockRequest.mockImplementation(async (url) => {
    if (url === '/api/auth/registration') return { data: { open } }
    throw new Error(`Unexpected request to ${url}`)
  })
}

function mount() {
  return renderAt('/register', {
    '/register': <RegisterPage />,
    '/login': <p>Login page</p>,
  })
}

describe('RegisterPage', () => {
  beforeEach(() => {
    mockRequest.mockReset()
    register.mockReset()
  })

  afterEach(() => {
    cleanup()
  })

  it('offers the form while registration is open', async () => {
    answerRegistrationState(true)
    mount()

    expect(await screen.findByLabelText('Email')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Register' })).toBeEnabled()
    expect(screen.queryByText(CLOSED_MESSAGE)).not.toBeInTheDocument()
    expect(mockRequest).toHaveBeenCalledWith('/api/auth/registration', expect.objectContaining({ method: 'GET' }))
  })

  it('says registration is closed instead of offering the form', async () => {
    answerRegistrationState(false)
    mount()

    expect(await screen.findByText(CLOSED_MESSAGE)).toHaveAttribute('role', 'status')
    expect(screen.queryByLabelText('Email')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to log in' })).toHaveAttribute('href', '/login')
  })

  it('switches to the closed state when the server refuses a registration as closed', async () => {
    answerRegistrationState(true)
    register.mockRejectedValue(httpError(403, 'Registration is closed. Ask the administrator for an account.', {}, {
      reason: 'registration_closed',
    }))
    mount()
    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Email'), 'listener@baander.app')
    await user.type(screen.getByLabelText('Password'), 'a-long-password')
    await user.type(screen.getByLabelText('Confirm password'), 'a-long-password')
    await user.click(screen.getByRole('button', { name: 'Register' }))

    expect(await screen.findByText(CLOSED_MESSAGE)).toHaveAttribute('role', 'status')
    expect(register).toHaveBeenCalledWith('listener@baander.app', 'a-long-password', undefined)
    expect(screen.queryByLabelText('Email')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('offers the form when the state cannot be read, leaving the decision to the server', async () => {
    mockRequest.mockRejectedValue(httpError(503, 'Service unavailable.'))
    mount()

    expect(await screen.findByLabelText('Email')).toBeInTheDocument()
    expect(screen.queryByText(CLOSED_MESSAGE)).not.toBeInTheDocument()
  })
})
