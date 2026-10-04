import type { ReactNode } from 'react'
import { ThemeProvider } from 'styled-components'
import { fireEvent, render, screen } from '@testing-library/react'
import { expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import { CronExpressionInput } from '@/features/admin/components/scheduler/CronExpressionInput'

function wrapper({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
      {children}
    </ThemeProvider>
  )
}

it('derives custom fields immediately from the controlled expression', () => {
  const onChange = vi.fn()
  const { rerender } = render(
    <CronExpressionInput value="0 6 * * *" onChange={onChange} />,
    { wrapper },
  )

  fireEvent.click(screen.getByRole('button', { name: 'Custom' }))

  expect(screen.getAllByRole('textbox')[1]).toHaveValue('6')

  rerender(<CronExpressionInput value="15 9 * * 1" onChange={onChange} />)

  expect(screen.getAllByRole('textbox')[0]).toHaveValue('15')
  expect(screen.getAllByRole('textbox')[1]).toHaveValue('9')

  fireEvent.change(screen.getAllByRole('textbox')[0], {
    target: { value: '30' },
  })

  expect(onChange).toHaveBeenCalledWith('30 9 * * 1')
})
