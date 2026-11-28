import { fireEvent, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation, useNavigate } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { render } from '../../../../../tests/test-utils'
import { AdminLibraryPage } from '../AdminLibraryPage'

vi.mock('@/features/library/pages/LibrariesPage', () => ({ LibrariesPage: () => null }))
vi.mock('../MetadataPage', () => ({ MetadataPage: () => <h2>Metadata controls</h2> }))
vi.mock('../GenresPage', () => ({ GenresPage: () => null }))
vi.mock('../AlbumDuplicatesPage', () => ({ AlbumDuplicatesPage: () => null }))
vi.mock('../LyricsAdminPage', () => ({ LyricsAdminPage: () => null }))

function CurrentLocation() {
  const { pathname, search, hash } = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <output>{pathname}{search}{hash}</output>
      <button type="button" onClick={() => navigate(-1)}>Go back</button>
    </>
  )
}

describe('AdminLibraryPage', () => {
  it('replaces a Matching bookmark with Metadata and preserves the other URL state', async () => {
    render(
      <MemoryRouter initialEntries={['/admin/library?tab=libraries', '/admin/library?tab=matching&library=rock#reviews']}>
        <AdminLibraryPage />
        <CurrentLocation />
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { name: 'Metadata controls' })).toBeInTheDocument()
    expect(screen.getByText('/admin/library?tab=metadata&library=rock#reviews')).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Matching' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Go back' }))
    await waitFor(() => expect(screen.getByText('/admin/library?tab=libraries')).toBeInTheDocument())
  })
})
