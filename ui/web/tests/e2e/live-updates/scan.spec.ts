import { authenticatedGet } from './capacity-fixtures'
import { test, expect, navigate, observeReads, paint } from './recovery-fixtures'

interface ScanPreparation { title: string; hash: string; library: { slug: string } }
interface ScannedSong { public_id: string; title: string; hash: string }
interface ScanStatus {
  drained: boolean
  pending: string[]
  ingested: ScannedSong[]
  jobs: { jobId: string; type: string; status: string }[]
  [key: string]: unknown
}

test('a real console scan and asynchronous ingest refresh the mounted album', async ({ run, request }) => {
  test.setTimeout(300_000)
  const healthResponse = await request.get('/health')
  expect(healthResponse.ok()).toBe(true)
  const health = await healthResponse.json() as {
    checks: { component: string; status: string; details: unknown }[]
  }
  run.evidence.push({ kind: 'worker-health-before-scan', health })
  expect(health.checks.find(check => check.component === 'messenger'),
    'asynchronous ingestion requires a live admitted worker').toMatchObject({ status: 'healthy' })
  const { receipt: source } = await run.fixture.request<ScanPreparation>({ op: 'prepare-scan' })
  const page = await run.open(1)
  const albumPath = `/api/albums/${run.manifest.album.publicId}`
  const before = await authenticatedGet<{ data: { title: string; songs: { title: string }[] } }>(page, albumPath)
  expect(before.data.songs.some(song => song.title === source.title)).toBe(false)
  await navigate(page, `/music/albums/${run.manifest.album.publicId}`)
  await expect(page.locator('main').getByText(before.data.title, { exact: true }).first()).toBeVisible()
  await expect(page.locator('main').getByText(source.title, { exact: true })).toHaveCount(0)
  const reads = observeReads(page, albumPath)
  const samples: ScanStatus[] = []
  run.evidence.push({ kind: 'real-scan-and-ingest', source, reads, samples })
  await run.managed(['exec', '--', 'php', 'bin/console', 'app:library:scan',
    source.library.slug, '--json', '--no-interaction'])
  await expect(page.locator('main').getByText(source.title, { exact: true })).toBeVisible({ timeout: 90_000 })
  const frame = await paint(page)
  expect(frame.visible).toBe(true)
  await expect.poll(async () => {
    const { receipt } = await run.fixture.request<ScanStatus>({ op: 'scan-status' })
    samples.push(receipt)
    return receipt.drained && receipt.ingested.length === 1
  }, { timeout: 90_000, intervals: [500, 1000, 2000] }).toBe(true)
  const final = samples.at(-1)!
  expect(final.ingested[0]).toMatchObject({ title: source.title, hash: source.hash })
  expect(final.jobs.some(job => job.type === 'library.scan' && job.status === 'finished')).toBe(true)
  expect(final.jobs.some(job => job.type === 'library.files_discovered' && job.status === 'finished')).toBe(true)
  expect(final.pending).toEqual([])
  expect(reads.some(read => read.status === 200)).toBe(true)
  const authoritative = await authenticatedGet<{ data: { songs: { title: string }[] } }>(page, albumPath)
  expect(authoritative.data.songs.filter(song => song.title === source.title)).toHaveLength(1)
  run.evidence.push({ kind: 'real-scan-rendered', frame, final: final.ingested })
})
