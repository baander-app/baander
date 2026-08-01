import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider as SCTypedThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { SmartPlaylistEditor, type SmartRule } from '@/features/playlist/components/SmartPlaylistEditor'

const testTheme = resolveTheme('dark', 'violet')

function renderWithTheme(ui: React.ReactElement) {
  return render(<SCTypedThemeProvider theme={testTheme}>{ui}</SCTypedThemeProvider>)
}

describe('SmartPlaylistEditor', () => {
  it('renders empty state when no rules', () => {
    renderWithTheme(<SmartPlaylistEditor rules={[]} onChange={vi.fn()} />)
    expect(screen.getByText('No rules yet. Add a rule to filter songs automatically.')).toBeInTheDocument()
  })

  it('calls onChange with a new rule when Add rule is clicked', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    renderWithTheme(<SmartPlaylistEditor rules={[]} onChange={onChange} />)

    await user.click(screen.getByText('Add rule'))
    expect(onChange).toHaveBeenCalledWith([
      expect.objectContaining({ field: 'genre', operator: 'equals', value: '' }),
    ])
  })

  it('renders existing rules with field and operator labels', () => {
    const rules: SmartRule[] = [
      { id: 'r1', field: 'genre', operator: 'equals', value: 'Rock' },
      { id: 'r2', field: 'year', operator: 'greater_than', value: '2020' },
    ]
    renderWithTheme(<SmartPlaylistEditor rules={rules} onChange={vi.fn()} />)

    expect(screen.getByText('Genre')).toBeInTheDocument()
    expect(screen.getByText('Equals')).toBeInTheDocument()
    expect(screen.getByText('Year')).toBeInTheDocument()
    expect(screen.getByText('Greater than')).toBeInTheDocument()
  })

  it('removes a rule when trash button is clicked', async () => {
    const user = userEvent.setup()
    const rules: SmartRule[] = [
      { id: 'r1', field: 'genre', operator: 'equals', value: 'Rock' },
      { id: 'r2', field: 'year', operator: 'is_empty', value: '' },
    ]
    const onChange = vi.fn()
    renderWithTheme(<SmartPlaylistEditor rules={rules} onChange={onChange} />)

    const removeButtons = screen.getAllByRole('button', { name: '' })
    const trashButtons = removeButtons.filter(
      (btn) => btn.querySelector('.lucide-trash-2'),
    )
    expect(trashButtons.length).toBe(2)

    await user.click(trashButtons[0])
    expect(onChange).toHaveBeenCalledWith([rules[1]])
  })

  it('shows value input for operators that need a value', () => {
    const rules: SmartRule[] = [
      { id: 'r1', field: 'genre', operator: 'equals', value: 'Rock' },
    ]
    renderWithTheme(<SmartPlaylistEditor rules={rules} onChange={vi.fn()} />)

    const input = screen.getByPlaceholderText('Value...')
    expect(input).toBeInTheDocument()
    expect(input).toHaveValue('Rock')
  })

  it('shows badge instead of input for operators without value', () => {
    const rules: SmartRule[] = [
      { id: 'r1', field: 'genre', operator: 'is_empty', value: '' },
    ]
    renderWithTheme(<SmartPlaylistEditor rules={rules} onChange={vi.fn()} />)

    // Both the operator dropdown trigger and the badge show "Is empty"
    const isEmptyElements = screen.getAllByText('Is empty')
    expect(isEmptyElements.length).toBe(2)
    expect(screen.queryByPlaceholderText('Value...')).not.toBeInTheDocument()
  })
})
