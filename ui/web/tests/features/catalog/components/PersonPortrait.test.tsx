import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PersonPortrait } from '@/features/catalog/components/PersonPortrait'

const { readImage } = vi.hoisted(() => ({ readImage: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get: readImage } }))
const clients: QueryClient[] = []
beforeEach(() => {
  vi.stubGlobal('URL', class extends URL {
    static createObjectURL = vi.fn(() => 'blob:https://baander.app/person')
    static revokeObjectURL = vi.fn()
  })
})
afterEach(() => {
  cleanup()
  clients.splice(0).forEach(client => client.clear())
  readImage.mockReset()
  vi.unstubAllGlobals()
})
function portrait(url: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  clients.push(client)
  return render(
    <QueryClientProvider client={client}>
      <PersonPortrait url={url} />
    </QueryClientProvider>,
  )
}

describe('Person portraits', () => {
  it('loads an app-local portrait through the authenticated client and releases its object URL', async () => {
    readImage.mockResolvedValue({ data: new Blob(['portrait'], { type: 'image/jpeg' }) })
    const view = portrait('/api/images/portrait/file')
    await waitFor(() => expect(view.container.querySelector('img')).toHaveAttribute('src', 'blob:https://baander.app/person'))
    expect(readImage).toHaveBeenCalledExactlyOnceWith('/api/images/portrait/file', {
      responseType: 'blob', signal: expect.any(AbortSignal),
    })
    view.unmount()
    expect(URL.revokeObjectURL).toHaveBeenCalledExactlyOnceWith('blob:https://baander.app/person')
  })

  it('leaves TMDB portraits on ordinary image requests', () => {
    const url = 'https://image.tmdb.org/t/p/w185/portrait.jpg'
    const view = portrait(url)
    expect(view.container.querySelector('img')).toHaveAttribute('src', url)
    expect(readImage).not.toHaveBeenCalled()
    expect(URL.createObjectURL).not.toHaveBeenCalled()
  })
})
