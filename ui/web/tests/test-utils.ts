import type React from 'react'
import { render as rtlRender, type RenderOptions } from '@testing-library/react'
import { TestThemeProvider } from './TestThemeProvider'

/**
 * Render with styled-components theme already provided.
 * Drop-in replacement for @testing-library/react's render().
 */
export function render(ui: React.ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
  return rtlRender(ui, { wrapper: TestThemeProvider, ...options })
}

export { rtlRender }

export { testTheme } from './test-theme'
export { TestThemeProvider } from './TestThemeProvider'
