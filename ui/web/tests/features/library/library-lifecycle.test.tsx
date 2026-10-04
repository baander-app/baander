import type { ReactNode } from 'react'
import { ThemeProvider } from 'styled-components'
import { act, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react'
import { expect, it, vi } from 'vitest'
import { resolveTheme } from '@/shared/theme/resolve-theme'
import {
  CreateLibraryDialog,
  EditLibraryDialog,
  ScanLibraryDialog,
} from '@/features/library/components/LibraryDialogs'
import { usePathValidation } from '@/features/library/hooks/use-path-validation'
import { validatePath, type Library } from '@/features/library/api/library-api'

vi.mock('@/features/library/api/library-api', () => ({
  LIBRARY_TYPES: ['music', 'movie'],
  validatePath: vi.fn(),
}))

type ValidationResult = Awaited<ReturnType<typeof validatePath>>

function wrapper({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider theme={resolveTheme('dark', 'violet')}>
      {children}
    </ThemeProvider>
  )
}

const library: Library = {
  id: 'library-a',
  name: 'Music',
  slug: 'music',
  path: '/music',
  type: 'music',
  sortOrder: 2,
  lastScan: null,
  scanStatus: null,
  createdAt: '',
  updatedAt: '',
}

it('discards create drafts when closed and initializes the next opening cleanly', () => {
  const props = {
    onClose: vi.fn(),
    onSubmit: vi.fn(),
    isPending: false,
  }
  const { rerender } = render(
    <CreateLibraryDialog open {...props} />,
    { wrapper },
  )

  fireEvent.change(screen.getByPlaceholderText('My Music'), {
    target: { value: 'Old draft' },
  })
  rerender(<CreateLibraryDialog open={false} {...props} />)
  rerender(<CreateLibraryDialog open {...props} />)

  expect(screen.getByPlaceholderText('My Music')).toHaveValue('')
})

it('owns edit drafts and scan options by the selected library', () => {
  const props = {
    onClose: vi.fn(),
    onSubmit: vi.fn(),
    isPending: false,
  }
  const { rerender, unmount } = render(
    <EditLibraryDialog library={library} {...props} />,
    { wrapper },
  )

  fireEvent.change(screen.getByRole('textbox'), {
    target: { value: 'Draft' },
  })
  const next = {
    ...library,
    id: 'library-b',
    name: 'Movies',
    sortOrder: 5,
  }
  rerender(<EditLibraryDialog library={next} {...props} />)

  expect(screen.getByRole('textbox')).toHaveValue('Movies')
  expect(screen.getByRole('spinbutton')).toHaveValue(5)

  unmount()
  const scan = render(
    <ScanLibraryDialog
      library={library}
      onClose={props.onClose}
      onConfirm={vi.fn()}
      isPending={false}
    />,
    { wrapper },
  )

  fireEvent.click(screen.getByRole('checkbox'))
  scan.rerender(
    <ScanLibraryDialog
      library={next}
      onClose={props.onClose}
      onConfirm={vi.fn()}
      isPending={false}
    />,
  )

  expect(screen.getByRole('checkbox')).not.toBeChecked()
})

it('ignores validation replies after path reset or a newer request', async () => {
  let release!: (value: ValidationResult) => void
  vi.mocked(validatePath)
    .mockReturnValueOnce(new Promise((resolve) => {
      release = resolve
    }))
    .mockResolvedValue({
      valid: true,
      error: null,
      resolvedPath: '/new',
    })
  const { result } = renderHook(() => usePathValidation())
  let first!: Promise<void>

  act(() => {
    first = result.current.validate('/old')
  })
  await waitFor(() => expect(validatePath).toHaveBeenCalledWith('/old'))
  act(() => result.current.reset())
  await act(async () => {
    await result.current.validate('/new')
  })
  await act(async () => {
    release({
      valid: false,
      error: 'Old path',
      resolvedPath: null,
    })
    await first
  })

  expect(result.current.result).toEqual({
    valid: true,
    error: null,
    resolvedPath: '/new',
  })
  expect(result.current.isValidating).toBe(false)
})
