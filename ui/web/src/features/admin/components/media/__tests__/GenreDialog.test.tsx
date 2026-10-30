import { render } from '../../../../../../tests/test-utils'
import { act, cleanup, fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { GenreDialog } from '../GenreDialog'
import { advanceAuthorization } from '@/shared/live/ownership'
import type { Genre } from '../../../api/genre-admin-api'

const { update, create } = vi.hoisted(() => ({ update: vi.fn(), create: vi.fn() }))
vi.mock('../../../hooks/use-genre-admin', () => ({
  useCreateGenre: () => ({ mutate: create }),
  useUpdateGenre: () => ({ mutate: update }),
}))
const genre: Genre = { publicId: 'genre', name: 'Rock', slug: 'rock', parentId: null, mbid: null }
beforeEach(() => { update.mockReset(); create.mockReset() })
afterEach(cleanup)

describe('genre editor snapshots', () => {
  it('preserves edits and requires conflict resolution before overwriting a changed genre', async () => {
    const close = vi.fn()
    const view = render(<GenreDialog open onOpenChange={close} genre={genre} genres={[genre]} />)
    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'My rock' } })
    const remote = { ...genre, name: 'Remote rock' }
    view.rerender(<GenreDialog open onOpenChange={close} genre={genre} genres={[remote]} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('This genre changed')
    expect(screen.getAllByRole('textbox')[0]).toHaveValue('My rock')
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Keep my edits' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(update).toHaveBeenCalledWith({ slug: 'rock', name: 'My rock', newSlug: 'rock' }, expect.any(Object))
    const callbacks = update.mock.calls[0][1]
    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Newer rock' } })
    callbacks.onSuccess()
    await waitFor(() => expect(screen.getAllByRole('textbox')[0]).toHaveValue('Newer rock'))
    expect(close).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('disables a deleted entity and removes protected server fields on authority loss', async () => {
    const view = render(<GenreDialog open onOpenChange={vi.fn()} genre={genre} genres={[genre]} />)
    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'Recover this text' } })
    view.rerender(<GenreDialog open onOpenChange={vi.fn()} genre={genre} genres={[]} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('unavailable')
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
    act(() => { advanceAuthorization() })
    await waitFor(() => expect(screen.getAllByRole('textbox')[1]).toHaveValue(''))
    expect(screen.getAllByRole('textbox')[0]).toHaveValue('Recover this text')
    view.rerender(<GenreDialog open onOpenChange={vi.fn()} genre={genre} genres={[genre]} />)
    expect(screen.getAllByRole('textbox')[1]).toHaveValue('')
  })
})
