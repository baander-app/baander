import { cleanup, screen } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { SmartPlaylistEditor, type SmartRule } from '../SmartPlaylistEditor'

afterEach(cleanup)

function rules(count: number): SmartRule[] {
  return Array.from({ length: count }, (_, index) => ({
    id: `rule-test-${index}`,
    field: 'genre',
    operator: 'equals',
    value: 'Jazz',
  }))
}

it('offers another rule below the server limit and bounds each value', () => {
  render(<SmartPlaylistEditor rules={rules(49)} onChange={vi.fn()} />)

  expect(screen.getByRole('button', { name: 'Add rule' })).toBeEnabled()
  for (const input of screen.getAllByPlaceholderText('Value...')) {
    expect(input).toHaveAttribute('maxLength', '255')
  }
})

it('stops adding rules at the server limit of 50', () => {
  const onChange = vi.fn()
  render(<SmartPlaylistEditor rules={rules(50)} onChange={onChange} />)

  expect(screen.getByRole('button', { name: 'Add rule' })).toBeDisabled()
  expect(screen.getByText('A smart playlist holds at most 50 rules.')).toBeInTheDocument()
})
