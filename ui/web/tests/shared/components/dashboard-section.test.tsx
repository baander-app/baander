import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { DashboardSection } from '@/shared/components/dashboard-section'

describe('DashboardSection', () => {
  it('renders title and children', () => {
    render(
      <DashboardSection title="Recently Added">
        <div>Child content</div>
      </DashboardSection>,
    )

    expect(screen.getByText('Recently Added')).toBeInTheDocument()
    expect(screen.getByText('Child content')).toBeInTheDocument()
  })

  it('renders action slot when provided', () => {
    render(
      <DashboardSection title="Albums" action={<button type="button">View All</button>}>
        <div>Content</div>
      </DashboardSection>,
    )

    expect(screen.getByText('View All')).toBeInTheDocument()
  })
})
