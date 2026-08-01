import React from 'react'
import { render as rtlRender, type RenderOptions } from '@testing-library/react'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'

const testTheme = resolveTheme('dark', 'violet')

/**
 * A ThemeProvider wrapper for tests that render styled-components.
 * Without this, styled-components will throw "Cannot read properties of undefined (reading 'lg')".
 */
export function TestThemeProvider({ children }: { children: React.ReactNode }) {
  return <SCTypedThemeProvider theme={testTheme}>{children}</SCTypedThemeProvider>
}

/**
 * Render with styled-components theme already provided.
 * Drop-in replacement for @testing-library/react's render().
 */
export function render(ui: React.ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
  return rtlRender(ui, { wrapper: TestThemeProvider, ...options })
}

export { rtlRender }

export { testTheme }
