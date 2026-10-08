import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ThemeProvider } from 'styled-components'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { LoginPage } from '../../pages/LoginPage'
import { LocationProbe } from '../../pages/__tests__/LocationProbe'
import { ProtectedRoute } from '../ProtectedRoute'

vi.mock('@/features/auth/stores/auth-store', async () => {
  const { create } = await import('zustand')

  interface MockAuthState {
    isAuthenticated: boolean
    isLoading: boolean
    login: () => Promise<void>
  }

  const useAuthStore = create<MockAuthState>()((set) => ({
    isAuthenticated: false,
    isLoading: false,
    login: async () => {
      set({ isAuthenticated: true })
    },
  }))

  return { useAuthStore }
})

const { useAuthStore } = await import('@/features/auth/stores/auth-store')

function mount(entry: string) {
  return render(
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
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
    </ThemeProvider>,
  )
}

async function logIn() {
  const user = userEvent.setup()
  await user.type(screen.getByLabelText('Email'), 'listener@baander.app')
  await user.type(screen.getByLabelText('Password'), 'correct horse battery staple')
  await user.click(screen.getByRole('button', { name: 'Log in' }))
}

describe('sign-in guard', () => {
  beforeEach(() => {
    useAuthStore.setState({ isAuthenticated: false })
  })

  afterEach(() => {
    cleanup()
  })

  it('keeps the device code through the login page', async () => {
    mount('/device?user_code=BCDF-GHJK')

    expect(screen.getByTestId('location')).toHaveTextContent(/^\/login$/)
    await logIn()

    expect(await screen.findByText('Device page')).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/device\?user_code=BCDF-GHJK$/)
  })

  it('keeps the full authorization query through the login page', async () => {
    const query = '?response_type=code&client_id=tv-app&redirect_uri=https%3A%2F%2Fapp.baander.app%2Fcb'
      + '&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&state=s1'
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
