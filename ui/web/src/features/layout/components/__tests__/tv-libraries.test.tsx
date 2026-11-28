import { cleanup, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, expect, it, vi } from 'vitest'
import { SidebarContent } from '../SidebarContent'
import { useMediaModeStore } from '../../stores/media-mode-store'

const { useLibraries } = vi.hoisted(() => ({
  useLibraries: vi.fn(() => ({ data: [
    { publicId: 'orbit', name: 'Orbit' },
    { publicId: 'archive', name: 'Archive' },
  ] })),
}))
vi.mock('@/features/library/hooks/use-libraries', () => ({ useLibraries }))
vi.mock('../../hooks/use-recent-items', () => ({
  useRecentItems: () => ({ items: [], isLoading: false }),
}))

afterEach(() => {
  cleanup()
  useMediaModeStore.setState({ activeMedia: 'music' })
})

it('links each accessible TV library to its grid and highlights only the selected library', () => {
  useMediaModeStore.setState({ activeMedia: 'tv' })
  render(<MemoryRouter initialEntries={['/tv/shows?library=archive']}><SidebarContent /></MemoryRouter>)

  expect(useLibraries).toHaveBeenCalledWith('tv_show')
  const group = within(screen.getByRole('group', { name: 'TV libraries' }))
  expect(group.getByRole('link', { name: 'Orbit' })).toHaveAttribute('href', '/tv/shows?library=orbit')
  expect(group.getByRole('link', { name: 'Archive' })).toHaveAttribute('aria-current', 'page')
  expect(screen.getAllByRole('link').filter(link => link.getAttribute('aria-current') === 'page')).toHaveLength(1)
  expect(screen.queryByRole('link', { name: 'Watchlists' })).toBeNull()
})
