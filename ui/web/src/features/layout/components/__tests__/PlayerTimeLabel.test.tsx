import { describe, expect, it } from 'vitest'
import { declaration, renderWithCss, rulesForElement } from '@/shared/theme/__tests__/css-text'
import { PlayerTimeLabel } from '../PlayerTimeLabel'

describe('PlayerTimeLabel', () => {
  it('sets the elapsed time in the timecode role with tabular numerals', () => {
    const { container, cssText } = renderWithCss(<PlayerTimeLabel />)
    const label = container.firstElementChild!
    const [rule] = rulesForElement(label, cssText)

    expect(label.textContent).toBe('00:00')
    expect(declaration(rule, 'font-family')).toBe('var(--font-mono)')
    expect(declaration(rule, 'font-size')).toBe('calc(var(--text-timecode-size) + var(--script-size-boost, 0px))')
    expect(declaration(rule, 'font-variant-numeric')).toBe('tabular-nums')
  })
})
