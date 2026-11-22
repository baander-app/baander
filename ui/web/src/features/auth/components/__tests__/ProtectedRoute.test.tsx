import { act, cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { customInstance } from '@/shared/api-client/axios-instance'
import type { UserResource } from '@/shared/api-client/gen/endpoints'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { LoginPage } from '../../pages/LoginPage'
import { LocationProbe } from '../../pages/__tests__/LocationProbe'
import { ProtectedRoute } from '../ProtectedRoute'

vi.mock('@/features/auth/stores/auth-store', async () => {
  const { create } = await import('zustand')

  interface MockUser {
    publicId: string
    email: string
    name: string | null
    roles: string[]
    emailVerifiedAt?: string | null
    pendingEmail?: string | null
  }

  interface MockAuthState {
    isAuthenticated: boolean
    isLoading: boolean
    user: MockUser | null
    login: () => Promise<void>
    logout: () => Promise<void>
    updateUser: (updates: Partial<MockUser>) => void
  }

  const useAuthStore = create<MockAuthState>()((set) => ({
    isAuthenticated: false,
    isLoading: false,
    user: null,
    login: async () => {
      set({ isAuthenticated: true })
    },
    logout: async () => {
      set({ isAuthenticated: false, user: null })
    },
    updateUser: (updates) => {
      set((state) => ({ user: state.user === null ? null : { ...state.user, ...updates } }))
    },
  }))

  return { useAuthStore }
})

vi.mock('@/shared/api-client/axios-instance', () => ({
  customInstance: vi.fn(),
}))

const { useAuthStore } = await import('@/features/auth/stores/auth-store')
const mockRequest = vi.mocked(customInstance)

const REGISTRANT: UserResource = {
  publicId: 'usr_registrant',
  name: 'Registrant',
  email: 'registrant@baander.app',
  emailVerifiedAt: null,
  createdAt: '2026-11-22T09:00:00+00:00',
  roles: ['ROLE_USER'],
  totpEnabled: false,
  pendingEmail: null,
}

function mount(entry: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

  return render(
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[entry]}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route element={<ProtectedRoute />}>
              <Route path="/device" element={<p>Device page</p>} />
              <Route path="/oauth/authorize" element={<p>Consent page</p>} />
              <Route path="/" element={<p>Home</p>} />
            </Route>
          </Routes>
          <LocationProbe />
        </MemoryRouter>
      </QueryClientProvider>
    </ThemeProvider>,
  )
}

async function logIn() {
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('Email'), 'listener@baander.app')
  await user.type(screen.getByLabelText('Password'), 'correct horse battery staple')
  await user.click(screen.getByRole('button', { name: 'Log in' }))
}

/** The account the next sign-in is for, with the profile /api/auth/me answers for it. */
function signInAs(profile: UserResource) {
  useAuthStore.setState({
    user: {
      publicId: profile.publicId,
      name: profile.name,
      email: profile.email,
      roles: profile.roles,
      emailVerifiedAt: profile.emailVerifiedAt,
    },
  })
  answerProfile(profile)
}

function answerProfile(profile: UserResource) {
  mockRequest.mockImplementation(async (url: string) => {
    if (url === '/api/auth/me') return { data: profile }

    throw new Error(`Unexpected request to ${url}`)
  })
}

describe('sign-in guard', () => {
  beforeEach(() => {
    useAuthStore.setState({ isAuthenticated: false, user: null })
  })

  afterEach(() => {
    cleanup()
    mockRequest.mockReset()
  })

  it('keeps the device code through the login page', async () => {
    mount('/device?user_code=BCDF-GHJK')

    expect(screen.getByTestId('location')).toHaveTextContent(/^\/login$/)
    await logIn()

    expect(await screen.findByText('Device page')).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/device\?user_code=BCDF-GHJK$/)
  })

  it('keeps the pushed request reference through the login page', async () => {
    const query = '?client_id=tv-app&request_uri=urn%3Aietf%3Aparams%3Aoauth%3Arequest_uri%3A6esc_11ACC5bwc014ltc14eY22c'
    mount(`/oauth/authorize${query}`)

    await logIn()

    expect(await screen.findByText('Consent page')).toBeInTheDocument()
    expect(screen.getByTestId('location').textContent).toBe(`/oauth/authorize${query}`)
  })

  it('opens the home page after a login that no guard started', async () => {
    mount('/login')

    await logIn()

    expect(await screen.findByText('Home')).toBeInTheDocument()
  })
})

describe('verification gate', () => {
  beforeEach(() => {
    useAuthStore.setState({ isAuthenticated: false, user: null })
  })

  afterEach(() => {
    cleanup()
    mockRequest.mockReset()
  })

  it('shows an account that has yet to verify its address the gate instead of the page it asked for', async () => {
    signInAs(REGISTRANT)
    mount('/device?user_code=BCDF-GHJK')

    await logIn()

    expect(await screen.findByRole('heading', { name: 'Verify your email address' })).toBeInTheDocument()
    expect(screen.getByText(/open the link we sent to registrant@baander\.app/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign out' })).toBeInTheDocument()
    expect(screen.queryByText('Device page')).not.toBeInTheDocument()
  })

  it('offers the email change with its step-up prompt from the gate', async () => {
    const user = userEvent.setup()
    signInAs(REGISTRANT)
    useAuthStore.setState({ isAuthenticated: true })
    mount('/')

    await user.click(await screen.findByRole('button', { name: 'Change email address' }))

    const dialog = await screen.findByRole('dialog', { name: 'Change email' })
    expect(dialog).toHaveTextContent('New email')
    expect(dialog).toHaveTextContent('Current password')
  })

  it('opens the page once the address was verified elsewhere and the window regains focus', async () => {
    signInAs(REGISTRANT)
    useAuthStore.setState({ isAuthenticated: true })
    mount('/device?user_code=BCDF-GHJK')
    expect(await screen.findByRole('heading', { name: 'Verify your email address' })).toBeInTheDocument()

    answerProfile({ ...REGISTRANT, emailVerifiedAt: '2026-11-22T10:00:00+00:00' })
    act(() => {
      window.dispatchEvent(new Event('focus'))
    })

    expect(await screen.findByText('Device page')).toBeInTheDocument()
    expect(useAuthStore.getState().user?.emailVerifiedAt).toBe('2026-11-22T10:00:00+00:00')
  })
})
