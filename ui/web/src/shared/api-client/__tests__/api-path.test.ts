import { describe, expect, it } from 'vitest'
import { apiPath } from '../api-path'
import { getGetAlbumShowUrl, getGetLibraryShowUrl } from '../gen/endpoints'

describe('apiPath', () => {
  it('encodes a route value as one path segment', () => {
    const libraryId = '../artists/x?'

    expect(apiPath`/api/libraries/${libraryId}`).toBe('/api/libraries/..%2Fartists%2Fx%3F')
  })

  it('encodes the fragment, percent and space characters of each value', () => {
    const first = 'a#b'
    const second = '100% done'

    expect(apiPath`/api/genres/${first}/songs/${second}`).toBe('/api/genres/a%23b/songs/100%25%20done')
  })

  it('keeps the literal text and accepts numbers', () => {
    const page = 3

    expect(apiPath`/api/admin/users/${'user-1'}/libraries/${page}`).toBe('/api/admin/users/user-1/libraries/3')
  })

  it.each(['', '.', '..'])('refuses %j, which cannot stand as one path segment', (value) => {
    expect(() => apiPath`/api/libraries/${value}/scan`).toThrow(/path segment/)
  })
})

describe('generated URL builders', () => {
  it('encode the slash, question mark, hash and percent of a path parameter', () => {
    expect(getGetAlbumShowUrl('a/b?c#d%e')).toBe('/api/albums/a%2Fb%3Fc%23d%25e')
    expect(getGetLibraryShowUrl('../artists/x?')).toBe('/api/libraries/..%2Fartists%2Fx%3F')
  })
})
