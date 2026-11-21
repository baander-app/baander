import { expect, test } from '@playwright/test'
import { FixtureClient, login, type FixtureManifest } from '../live-updates/capacity-fixtures'

interface DirectorManifest extends FixtureManifest {
  movie: {
    publicId: string
  }
  directors: {
    publicId: string
    name: string
    tmdbId: number
  }[]
}

test('directors browse real credits, paginate and open their movies', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(180_000)
  if (!baseURL) throw new Error('An explicit isolated preview URL is required.')
  const fixture = FixtureClient.start('tests/Fixtures/Playback/video-fixture.php')
  const run = `directors-${Date.now()}`
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  const page = await context.newPage()
  const failures: unknown[] = []
  try {
    const manifest = (await fixture.request<DirectorManifest>({
      op: 'create',
      run,
      directors: true,
    })).receipt
    expect(manifest.directors).toHaveLength(51)
    await login(page, manifest.users[0]!)
    await page.getByTestId('media-tab-movies').click()
    await expect(page).toHaveURL(/\/movies$/)
    await page.getByRole('link', { name: 'Directors', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Directors', exact: true }))
      .toBeVisible()
    await page.getByRole('textbox', { name: 'Search directors' }).fill(run)
    await page.getByRole('button', { name: 'Search', exact: true }).click()
    await expect(page.getByRole('link', { name: /Owned director.*1 movie/ })).toHaveCount(50)
    await page.getByRole('button', { name: 'Next', exact: true }).click()
    await expect(page).toHaveURL(new RegExp(`q=${run}.*page=2`))
    const director = manifest.directors[50]!
    await page.getByRole('link', { name: `${director.name} 1 movie`, exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: director.name, exact: true }))
      .toBeVisible()
    const title = `Owned playback movie ${run}`
    await page.getByRole('button', { name: new RegExp(title) }).click()
    await expect(page).toHaveURL(new RegExp(`/movies/${manifest.movie.publicId}$`))
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Play', exact: true })).toBeVisible()
  } catch (error) {
    failures.push(error)
  } finally {
    await context.close().catch(error => failures.push(error))
    try {
      const cleanup = (await fixture.request({ op: 'cleanup', run })).receipt
      await testInfo.attach('director-fixture-cleanup', {
        body: JSON.stringify(cleanup),
        contentType: 'application/json',
      })
      expect(cleanup.complete).toBe(true)
    } catch (error) {
      failures.push(error)
    }
    await fixture.close().catch(error => failures.push(error))
  }
  if (failures.length > 0) {
    throw new AggregateError(failures, 'Director browsing or fixture cleanup failed.')
  }
})
