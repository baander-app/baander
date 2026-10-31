import { act, renderHook, waitFor } from '@testing-library/react'
import { QueryClientProvider } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { OwnedQueryClient } from '@/shared/live/query-client'
import { advanceAuthorization } from '@/shared/live/ownership'
import { useAlbumPalette } from './use-album-palette'
import { usePaletteStore } from '../stores/palette-store'
import type { PaletteColors } from '../types'

const { get, extract } = vi.hoisted(() => ({ get: vi.fn(), extract: vi.fn() }))
vi.mock('@/shared/api-client/axios-instance', () => ({ AXIOS_INSTANCE: { get } }))
vi.mock('../utils/extract-palette', () => ({ extractPalette: extract }))

const first: PaletteColors = { primary: '#111111', secondary: '#222222', accent: '#333333', background: '#000000', isDark: true }
const second: PaletteColors = { ...first, primary: '#abcdef' }
function extraction() {
  let resolve!: (palette: PaletteColors | null) => void
  const promise = new Promise<PaletteColors | null>(done => { resolve = done })
  return { promise, resolve }
}

let client: OwnedQueryClient
let detach: () => void
beforeEach(() => {
  get.mockReset()
  extract.mockReset()
  usePaletteStore.getState().clearAll()
  client = new OwnedQueryClient()
  client.setDefaultOptions({ queries: { retry: false } })
  detach = client.attach()
})
afterEach(() => {
  detach()
  client.dispose()
  usePaletteStore.getState().clearAll()
})
function mount(source = '/api/image/first') {
  return renderHook(({ url }) => useAlbumPalette('album', url), {
    initialProps: { url: source },
    wrapper: ({ children }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>,
  })
}

describe('authenticated album palettes', () => {
  it('uses shared authenticated bytes and ignores an older extraction after source replacement', async () => {
    const older = extraction()
    const newer = extraction()
    const oldBlob = new Blob(['old'])
    const newBlob = new Blob(['new'])
    let deliverNewBytes!: (response: { data: Blob }) => void
    const newResponse = new Promise<{ data: Blob }>(resolve => { deliverNewBytes = resolve })
    get.mockResolvedValueOnce({ data: oldBlob }).mockReturnValueOnce(newResponse)
    extract.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise)
    const hook = mount()
    await waitFor(() => expect(extract.mock.calls[0]?.[0]).toBe(oldBlob))
    hook.rerender({ url: '/api/image/second' })
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
    expect(extract).toHaveBeenCalledTimes(1)
    await act(async () => { deliverNewBytes({ data: newBlob }) })
    await waitFor(() => expect(extract.mock.calls[1]?.[0]).toBe(newBlob))
    await act(async () => { newer.resolve(second) })
    expect(usePaletteStore.getState().getPalette('album')).toEqual(second)
    await act(async () => { older.resolve(first) })
    expect(usePaletteStore.getState().getPalette('album')).toEqual(second)
    expect(get).toHaveBeenCalledTimes(2)
    hook.unmount()
  })

  it('recomputes a stable source when the shared binary query receives fresh bytes', async () => {
    get.mockResolvedValueOnce({ data: new Blob(['first']) }).mockResolvedValueOnce({ data: new Blob(['second']) })
    extract.mockResolvedValueOnce(first).mockResolvedValueOnce(second)
    const hook = mount()
    await waitFor(() => expect(usePaletteStore.getState().getPalette('album')).toEqual(first))
    await act(async () => { await client.invalidateQueries({ queryKey: ['catalog', 'binary-image'] }) })
    await waitFor(() => expect(usePaletteStore.getState().getPalette('album')).toEqual(second))
    hook.rerender({ url: '/api/image/first' })
    expect(extract).toHaveBeenCalledTimes(2)
    expect(get).toHaveBeenCalledTimes(2)
    hook.unmount()
  })

  it('cannot repopulate the mirror after a reset or unmount', async () => {
    const pending = extraction()
    get.mockResolvedValue({ data: new Blob(['image']) })
    extract.mockReturnValue(pending.promise)
    const hook = mount()
    await waitFor(() => expect(extract).toHaveBeenCalledTimes(1))
    act(() => usePaletteStore.getState().clearAll())
    await act(async () => { pending.resolve(first) })
    expect(usePaletteStore.getState().getPalette('album')).toBeNull()
    hook.unmount()
  })

  it('discards an extraction after same-principal authorization changes', async () => {
    const pending = extraction()
    get.mockResolvedValueOnce({ data: new Blob(['image']) }).mockReturnValue(new Promise(() => undefined))
    extract.mockReturnValue(pending.promise)
    const hook = mount()
    await waitFor(() => expect(extract).toHaveBeenCalledTimes(1))
    await act(async () => { advanceAuthorization() })
    await act(async () => { pending.resolve(first) })
    expect(usePaletteStore.getState().getPalette('album')).toBeNull()
    hook.unmount()
  })

  it('ignores extraction completion after unmount', async () => {
    const pending = extraction()
    get.mockResolvedValue({ data: new Blob(['image']) })
    extract.mockReturnValue(pending.promise)
    const hook = mount()
    await waitFor(() => expect(extract).toHaveBeenCalledTimes(1))
    hook.unmount()
    await act(async () => { pending.resolve(first) })
    expect(usePaletteStore.getState().getPalette('album')).toBeNull()
  })
})
