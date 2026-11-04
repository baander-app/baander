import { describe, expect, it } from 'vitest'
import { declaration, renderWithCss, rulesForElement } from '@/shared/theme/__tests__/css-text'
import { CronExpressionInput } from '../CronExpressionInput'

describe('CronExpressionInput', () => {
  it('shows the cron expression in the mono role, which reads its family from --font-mono', () => {
    const { container, cssText } = renderWithCss(<CronExpressionInput value="*/5 * * * *" onChange={() => {}} />)
    const expression = container.querySelector('code')!
    const rules = rulesForElement(expression, cssText)

    expect(expression).toHaveTextContent('*/5 * * * *')
    expect(rules.map(rule => declaration(rule, 'font-family'))).toContain('var(--font-mono)')
    expect(rules.map(rule => declaration(rule, 'font-size')))
      .toContain('calc(var(--text-mono-sm-size) + var(--script-size-boost, 0px))')
    expect(rules.map(rule => declaration(rule, 'font-family'))).not.toContain('monospace')
  })
})
