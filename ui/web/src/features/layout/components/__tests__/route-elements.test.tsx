import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation, useParams } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'
import { ParamRedirect } from '../route-elements'

function AlbumProbe() {
  const { publicId } = useParams()
  const location = useLocation()

  return (
    <>
      <output aria-label="album">{publicId}</output>
      <output aria-label="path">{location.pathname}</output>
    </>
  )
}

function OtherProbe() {
  const location = useLocation()

  return <output aria-label="other">{location.pathname}</output>
}

function renderRedirect(entry: string) {
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/albums/:publicId" element={<ParamRedirect to="/music/albums/:publicId" />} />
        <Route path="/music/albums/:publicId" element={<AlbumProbe />} />
        <Route path="*" element={<OtherProbe />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('ParamRedirect', () => {
  afterEach(cleanup)

  it('forwards a parameter as one encoded path segment', () => {
    renderRedirect('/albums/..%2Fadmin%3Ftab%3D1')

    expect(screen.getByLabelText('path')).toHaveTextContent('/music/albums/..%2Fadmin%3Ftab%3D1')
    expect(screen.getByLabelText('album')).toHaveTextContent('../admin?tab=1')
    expect(screen.queryByLabelText('other')).toBeNull()
  })

  it('forwards a plain public id unchanged', () => {
    renderRedirect('/albums/V1StGXR8_Z5jdHi6B-myT')

    expect(screen.getByLabelText('path')).toHaveTextContent('/music/albums/V1StGXR8_Z5jdHi6B-myT')
  })
})
