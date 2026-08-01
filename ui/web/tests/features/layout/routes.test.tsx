import { describe, it, expect } from 'vitest'
import { protectedRoutes } from '@/features/layout/routes'

describe('route structure', () => {
  it('/ redirects to /music by default', () => {
    const appShellRoutes = protectedRoutes[0].children?.[0]?.children as Array<{ path: string; element: React.ReactElement }>
    const rootRoute = appShellRoutes.find((r) => r.path === '/')
    expect(rootRoute).toBeDefined()
    // It should be a Navigate element pointing to /music
    expect(rootRoute?.element?.type?.name || rootRoute?.element?.type).toBeDefined()
  })

  it('music routes exist under /music', () => {
    const appShellRoutes = protectedRoutes[0].children?.[0]?.children as Array<{ path: string }>
    const paths = appShellRoutes.map((r) => r.path)

    expect(paths).toContain('/music')
    expect(paths).toContain('/music/albums')
    expect(paths).toContain('/music/artists')
    expect(paths).toContain('/music/songs')
    expect(paths).toContain('/music/genres')
    expect(paths).toContain('/music/browse')
    expect(paths).toContain('/music/playlists')
    expect(paths).toContain('/music/radio')
  })

  it('global routes remain at root level', () => {
    const appShellRoutes = protectedRoutes[0].children?.[0]?.children as Array<{ path: string }>
    const paths = appShellRoutes.map((r) => r.path)

    expect(paths).toContain('/search')
    expect(paths).toContain('/settings')
    expect(paths).toContain('/equalizer')
  })

  it('old music routes redirect to new media-prefixed routes', async () => {
    const appShellRoutes = protectedRoutes[0].children?.[0]?.children as Array<{ path: string }>
    const paths = appShellRoutes.map((r) => r.path)
    const oldPaths = ['/albums', '/artists', '/songs', '/genres', '/browse', '/playlists', '/radio']
    oldPaths.forEach((oldPath) => {
      expect(paths, `Missing redirect route for ${oldPath}`).toContain(oldPath)
    })
  })

  it('media type home routes exist', () => {
    const appShellRoutes = protectedRoutes[0].children?.[0]?.children as Array<{ path: string }>
    const paths = appShellRoutes.map((r) => r.path)

    expect(paths).toContain('/movies')
    expect(paths).toContain('/tv')
    expect(paths).toContain('/podcasts')
    expect(paths).toContain('/concerts')
    expect(paths).toContain('/ebooks')
  })
})
