import { type ReactElement, StrictMode } from 'react'
import { render } from '@testing-library/react'
import { AxiosError, AxiosHeaders } from 'axios'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { LocationProbe } from './LocationProbe'

/**
 * Renders the given routes inside a memory router starting at `entry`. The tree runs in
 * StrictMode, as the app does, so effects are replayed once on mount.
 */
export function renderAt(entry: string, routes: Record<string, ReactElement>) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } })

  return render(
    <StrictMode>
      <QueryClientProvider client={client}>
        <ThemeProvider theme={resolveTheme('dark', 'violet')}>
          <MemoryRouter initialEntries={[entry]}>
            <Routes>
              {Object.entries(routes).map(([path, element]) => (
                <Route key={path} path={path} element={element} />
              ))}
            </Routes>
            <LocationProbe />
          </MemoryRouter>
        </ThemeProvider>
      </QueryClientProvider>
    </StrictMode>,
  )
}

export function httpError(status: number, message: string, headers: Record<string, string> = {}) {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    data: { error: { code: status, message } },
    status,
    statusText: 'Error',
    headers,
    config: { headers: new AxiosHeaders() },
  })
}
