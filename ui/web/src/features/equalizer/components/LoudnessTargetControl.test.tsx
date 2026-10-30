import { useState } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ThemeProvider } from 'styled-components'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { LoudnessTargetControl } from './LoudnessTargetControl'

const theme = resolveTheme('dark', 'violet')

beforeEach(() => {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
  Element.prototype.scrollIntoView = () => {}
})

function Harness({ initial = -14, disabled = false, onChange }: {
  initial?: number
  disabled?: boolean
  onChange?: (value: number) => void
}) {
  const [value, setValue] = useState(initial)
  return (
    <ThemeProvider theme={theme}>
      <LoudnessTargetControl value={value} disabled={disabled} onChange={(next) => {
        setValue(next)
        onChange?.(next)
      }} />
      <output aria-label="Active target">{value}</output>
      <button onClick={() => setValue(-18)}>External update</button>
    </ThemeProvider>
  )
}

async function selectCustom() {
  const user = userEvent.setup()
  await user.click(screen.getByRole('combobox', { name: 'Loudness target' }))
  await user.click(screen.getByRole('option', { name: 'Custom' }))
  return user
}

describe('LoudnessTargetControl', () => {
  it('keeps Custom visible at the default and commits a fraction on Enter', async () => {
    render(<Harness />)
    const user = await selectCustom()
    const input = screen.getByRole('textbox', { name: 'Custom target (LUFS)' })
    expect(input).toHaveValue('-14')
    await user.clear(input)
    await user.type(input, '-20.5{Enter}')
    expect(screen.getByLabelText('Active target')).toHaveTextContent('-20.5')
    expect(input).toHaveValue('-20.5')
  })

  it('commits on blur and returns to a named preset', async () => {
    render(<Harness initial={-20.5} />)
    const user = userEvent.setup()
    const input = screen.getByRole('textbox', { name: 'Custom target (LUFS)' })
    await user.clear(input)
    await user.type(input, '-21.25')
    await user.tab()
    expect(screen.getByLabelText('Active target')).toHaveTextContent('-21.25')
    await user.click(screen.getByRole('combobox', { name: 'Loudness target' }))
    await user.click(screen.getByRole('option', { name: 'Balanced (−16 LUFS)' }))
    expect(screen.getByLabelText('Active target')).toHaveTextContent('-16')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it.each(['', 'abc', '1e309'])('rejects invalid draft %j without changing the active target', async (draft) => {
    const onChange = vi.fn()
    render(<Harness initial={-20.5} onChange={onChange} />)
    const input = screen.getByRole('textbox', { name: 'Custom target (LUFS)' })
    fireEvent.change(input, { target: { value: draft } })
    fireEvent.blur(input)
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription(/Enter a finite number in LUFS/)
    expect(onChange).not.toHaveBeenCalled()
    expect(screen.getByLabelText('Active target')).toHaveTextContent('-20.5')
  })

  it('replaces an incomplete draft when the target changes externally', () => {
    render(<Harness initial={-20.5} />)
    fireEvent.change(screen.getByRole('textbox'), { target: { value: '-' } })
    fireEvent.click(screen.getByRole('button', { name: 'External update' }))
    expect(screen.getByRole('combobox')).toHaveTextContent('Relaxed (−18 LUFS)')
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('disables both controls while normalization is off', () => {
    render(<Harness initial={-20.5} disabled />)
    expect(screen.getByRole('combobox')).toBeDisabled()
    expect(screen.getByRole('textbox')).toBeDisabled()
  })
})
