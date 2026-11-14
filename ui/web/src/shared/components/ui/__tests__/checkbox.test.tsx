import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Checkbox } from '../checkbox'

afterEach(cleanup)

describe('Checkbox', () => {
  it('reports each change of its tick', () => {
    const onCheckedChange = vi.fn()
    render(<Checkbox aria-label="Genres" defaultChecked onCheckedChange={onCheckedChange} />)

    const checkbox = screen.getByRole('checkbox', { name: 'Genres' })

    expect(checkbox).toBeChecked()

    fireEvent.click(checkbox)

    expect(checkbox).not.toBeChecked()
    expect(onCheckedChange).toHaveBeenCalledWith(false)
  })

  it('ignores clicks while disabled', () => {
    const onCheckedChange = vi.fn()
    render(<Checkbox aria-label="Title" disabled onCheckedChange={onCheckedChange} />)

    fireEvent.click(screen.getByRole('checkbox', { name: 'Title' }))

    expect(screen.getByRole('checkbox', { name: 'Title' })).not.toBeChecked()
    expect(onCheckedChange).not.toHaveBeenCalled()
  })
})
