import { expect, test } from '@playwright/test'
import { spaNavigate } from '../fixtures'
import { withVideoFixture } from './fixtures'

test('an administrator fixes a video review and the member sees the matched series', { tag: '@firefox' }, async ({ browser, baseURL }) => {
  test.setTimeout(240_000)
  if (!baseURL) throw new Error('An isolated E2E_BASE_URL is required.')
  await withVideoFixture(browser, baseURL, { delivery: 'original' }, async scope => {
    const { manifest, admin, member } = scope
    await spaNavigate(member)(`/tv/series/${manifest.review.seriesPublicId}`)
    await expect(member.getByRole('heading', { name: manifest.review.originalTitle, exact: true })).toBeVisible()
    await spaNavigate(admin)('/admin/library?tab=metadata')
    await expect(admin.getByRole('switch', { name: 'TheTVDB', exact: true })).not.toBeChecked()
    await expect(admin.getByRole('switch', { name: 'TMDB', exact: true })).not.toBeChecked()
    await expect(admin.getByRole('switch', { name: 'Local', exact: true })).toBeChecked()
    const reviews = admin.getByRole('region', { name: 'Video reviews', exact: true })
    await expect(reviews).toBeVisible()
    await expect(reviews.getByRole('combobox', { name: 'Library', exact: true })).toBeEnabled()
    await reviews.getByRole('combobox', { name: 'Library', exact: true }).selectOption(manifest.library.publicId)
    await reviews.getByRole('combobox', { name: 'Kind', exact: true }).selectOption('series')
    const row = reviews.getByRole('row').filter({ hasText: manifest.review.originalTitle })
    await expect(row).toHaveCount(1)
    await row.getByRole('button', { name: /^Review / }).click()
    const dialog = admin.getByRole('dialog', { name: 'Review video match', exact: true })
    await expect(dialog).toBeVisible()
    await expect(dialog.getByRole('tab', { name: 'Search', exact: true })).toHaveAttribute('aria-selected', 'true')
    await dialog.getByRole('textbox', { name: 'Search title', exact: true }).fill(manifest.review.matchedTitle)
    await dialog.getByRole('button', { name: 'Search', exact: true }).click()
    const candidate = dialog.getByRole('button', {
      name: `Apply Local files match ${manifest.review.matchedTitle}`,
      exact: true
    })
    await expect(candidate).toBeEnabled()
    const [response] = await Promise.all([
      admin.waitForResponse(result => result.request().method() === 'POST'
        && new URL(result.url()).pathname === `/api/admin/video-matches/series/${manifest.review.seriesPublicId}/apply`),
      candidate.click()
    ])
    expect(response.status()).toBe(200)
    expect(response.request().postDataJSON()).toEqual({ provider: 'local', candidateId: manifest.review.candidateId })
    await expect(dialog.getByText('Match applied. Review its ordering and file assignments.', { exact: true })).toBeVisible()
    await expect.poll(async () => (await scope.inspect()).review).toEqual({
      name: manifest.review.matchedTitle,
      matchedProvider: 'local',
      matchedManually: true,
      ordering: manifest.review.orderingId
    })
    await dialog.getByRole('tab', { name: 'Ordering', exact: true }).click()
    const ordering = dialog.getByRole('combobox', { name: 'Episode ordering', exact: true })
    await expect(ordering).toBeEnabled()
    await ordering.click()
    await admin.getByRole('option', { name: 'Aired', exact: true }).click()
    const [ordered] = await Promise.all([
      admin.waitForResponse(result => result.request().method() === 'PUT'
        && new URL(result.url()).pathname === `/api/admin/video-matches/series/${manifest.review.seriesPublicId}/ordering`),
      dialog.getByRole('button', { name: 'Save ordering', exact: true }).click()
    ])
    expect(ordered.status()).toBe(200)
    await expect(dialog.getByText('Ordering saved.', { exact: true })).toBeVisible()
    await dialog.getByRole('button', { name: 'Close', exact: true }).click()
    await expect(dialog).not.toBeVisible()
    await member.reload()
    await expect(member.getByRole('heading', { name: manifest.review.matchedTitle, exact: true })).toBeVisible()
    await expect(member.getByRole('heading', { name: manifest.review.originalTitle, exact: true })).toHaveCount(0)
    expect((await scope.inspect()).review.matchedManually).toBe(true)
  })
})
