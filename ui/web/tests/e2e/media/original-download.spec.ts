import { statSync } from 'node:fs'
import type { Page } from '@playwright/test'
import type * as MediaDelivery from '../../../src/features/player/services/media-delivery'
import { test, expect } from '../fixtures'

const ORIGINAL_TRACK = '/api/stream/original/track'

interface Negotiated {
  kind: string
  url: string | null
}

interface SongPage {
  data: { publicId: string }[]
}

/** Negotiates the first catalog track as the player does, with this engine's own capabilities. */
const negotiateFirstTrack = (page: Page): Promise<Negotiated> => page.evaluate(async () => {
  const clientPath = '/src/shared/api-client/axios-instance.ts'
  const deliveryPath = '/src/features/player/services/media-delivery.ts'
  const { AXIOS_INSTANCE } = await import(/* @vite-ignore */ clientPath) as {
    AXIOS_INSTANCE: { get: (path: string) => Promise<{ data: SongPage }> }
  }
  const delivery = await import(/* @vite-ignore */ deliveryPath) as typeof MediaDelivery

  const [track] = (await AXIOS_INSTANCE.get('/api/songs/?limit=1')).data.data
  if (!track) {
    throw new Error('Original media checks need a scanned library with at least one track.')
  }
  const capabilities = await delivery.detectDestinationCapabilities(new Audio(), 'track')
  const { kind, url } = await delivery.negotiateMediaDelivery(
    { kind: 'track', id: track.publicId, capabilities },
    new AbortController().signal,
  )

  return { kind, url }
})

test.describe('Original media', () => {
  test('a signed original opened as a page downloads without running script', { tag: '@firefox' }, async ({
    asAdmin: page,
    baseURL,
  }) => {
    const negotiated = await negotiateFirstTrack(page)
    expect(negotiated.kind).toBe('original')
    const signed = new URL(negotiated.url ?? '', baseURL)
    expect(signed.pathname).toBe(ORIGINAL_TRACK)
    for (const parameter of ['id', 'owner', 'exp', 'sig']) {
      expect(signed.searchParams.has(parameter), parameter).toBe(true)
    }

    const opened = await page.context().newPage()
    const scriptEvidence: string[] = []
    opened.on('console', (message) => scriptEvidence.push(`console: ${message.text()}`))
    opened.on('pageerror', (error) => scriptEvidence.push(`pageerror: ${error.message}`))
    const response = opened.waitForResponse((candidate) => candidate.url() === signed.href)
    const download = opened.waitForEvent('download')

    // A download aborts the navigation; rendering the file would commit a document at its URL.
    const navigation = await opened.goto(signed.href).then(() => 'committed', () => 'aborted')

    expect(navigation).toBe('aborted')
    const headers = await (await response).allHeaders()
    expect(headers['content-disposition']).toBe('attachment')
    expect(headers['content-security-policy']).toBe("default-src 'none'; sandbox")
    const file = await download
    expect(file.url()).toBe(signed.href)
    expect(await file.failure()).toBeNull()
    expect(statSync(await file.path()).size).toBe(Number(headers['content-length']))
    expect(opened.url()).toBe('about:blank')
    expect(scriptEvidence).toEqual([])
  })
})
