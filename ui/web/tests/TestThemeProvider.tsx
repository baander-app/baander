import type React from 'react'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { testTheme } from './test-theme'

/**
 * A ThemeProvider wrapper for tests that render styled-components.
 * Without this, styled-components will throw "Cannot read properties of undefined (reading 'lg')".
 */
export function TestThemeProvider({ children }: { children: React.ReactNode }) {
  return <SCTypedThemeProvider theme={testTheme}>{children}</SCTypedThemeProvider>
}
