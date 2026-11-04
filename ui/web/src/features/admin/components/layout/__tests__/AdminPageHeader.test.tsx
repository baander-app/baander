import { describe, expect, it } from 'vitest'
import { declaration, renderWithCss, rulesForElement } from '@/shared/theme/__tests__/css-text'
import { AdminPageHeader } from '../AdminPageHeader'

describe('AdminPageHeader', () => {
  it('sets the page heading in the title role and the subtitle in the body role', () => {
    const { container, cssText } = renderWithCss(<AdminPageHeader title="Users" subtitle="Manage accounts" />)
    const heading = container.querySelector('h1')!
    const subtitle = container.querySelector('p')!

    expect(heading).toHaveTextContent('Users')
    expect(rulesForElement(heading, cssText).map(rule => declaration(rule, 'font-size')))
      .toContain('calc(var(--text-title-size) + var(--script-size-boost, 0px))')
    expect(rulesForElement(subtitle, cssText).map(rule => declaration(rule, 'font-size')))
      .toContain('calc(var(--text-body-size) + var(--script-size-boost, 0px))')
  })
})
