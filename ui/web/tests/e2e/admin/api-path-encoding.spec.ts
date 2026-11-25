import { test, expect, spaNavigate } from '../fixtures'
import { main } from '../selectors'

const ARTIST_ID = 'V1StGXR8_Z5jdHi6B-myT'

test.describe('Admin - API path encoding', () => {
  test('a route parameter stays one segment of the API path it is placed in', async ({ asAdmin: page }) => {
    const apiPaths: string[] = []
    page.on('request', (request) => {
      const url = new URL(request.url())
      if (url.pathname.startsWith('/api/')) apiPaths.push(url.pathname)
    })

    const parameter = encodeURIComponent(`../artists/${ARTIST_ID}?`)
    await spaNavigate(page)(`/admin/library/${parameter}`)
    await expect(main(page)).toBeVisible()

    await expect.poll(() => apiPaths).toContain(`/api/libraries/${parameter}`)
    expect(apiPaths).not.toContain(`/api/artists/${ARTIST_ID}`)
    expect(apiPaths.filter((path) => path.startsWith('/api/artists/'))).toEqual([])
  })
})
