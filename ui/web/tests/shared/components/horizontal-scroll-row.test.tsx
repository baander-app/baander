import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { HorizontalScrollRow } from '@/shared/components/horizontal-scroll-row'

describe('HorizontalScrollRow', () => {
  it('renders children', () => {
    render(
      <HorizontalScrollRow>
        <div>Item 1</div>
        <div>Item 2</div>
        <div>Item 3</div>
      </HorizontalScrollRow>,
    )

    expect(screen.getByText('Item 1')).toBeInTheDocument()
    expect(screen.getByText('Item 2')).toBeInTheDocument()
    expect(screen.getByText('Item 3')).toBeInTheDocument()
  })

  it('applies flex and overflow styles', () => {
    const { container } = render(
      <HorizontalScrollRow>
        <div>Content</div>
      </HorizontalScrollRow>,
    )

    const row = container.firstChild as HTMLElement
    // styled-components generates hashed class names; verify computed styles instead
    expect(getComputedStyle(row).display).toBe('flex')
    expect(getComputedStyle(row).overflowX).toBe('auto')
  })
})
