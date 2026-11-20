import { test, expect, spaNavigate } from '../fixtures'
import { expectReadable, paintedPixels, textColor, luminance } from '../styling/helpers'
import { setTheme } from '../helpers/theme-utils'

const record = {
  id: 'local:web:1', sourceId: 'web', timestamp: '2026-11-20T12:34:56Z', level: 'info',
  channel: 'request', message: '<script>inert record</script>\nSecond line of the retained message\nThird line with more detail', context: { job: 'sync' }, extra: {},
  raw: '<script>inert record</script>', correlationId: 'trace-web-worker', node: 'local',
  service: 'web', stream: 'stderr', truncated: false,
}
const coverageReasons = Array.from({ length: 180 }, (_, index) => `Node ${index}: source-${'long-identifier-'.repeat(40)} has unavailable history`)
const coverage = { complete: false, reasons: [...coverageReasons, coverageReasons[0]] }
const snapshot = { id: 'browser-log-snapshot', createdAt: '2026-11-20T12:35:00Z' }

for (const width of [1280, 390]) {
  test(`logs retain source, message, inspector and SQL access at ${width}px`, async ({ asAdmin: page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 })
    let allowPartial: boolean | undefined
    await page.route('**/api/admin/logs/**', async route => {
      const url = new URL(route.request().url())
      const data = url.pathname.endsWith('/sources')
        ? { channels: ['request', 'security'], items: [{ id: 'web', name: 'Web stderr', node: 'local', service: 'web', status: 'following' }, { id: 'archive', name: 'worker.log.zip:worker.log', member: 'worker.log', node: 'local', service: 'worker', kind: 'zip', status: 'archived' }], nodes: [{ id: 'local', status: 'healthy' }, { id: 'worker-host', status: 'unreachable' }], coverage }
        : url.pathname.endsWith('/query')
          ? { columns: ['level', 'records'], rows: [['info', 1]], snapshot, coverage }
          : url.pathname.includes('/entries/') ? record : { items: [record], nextCursor: null, snapshot, coverage }
      if (url.pathname.endsWith('/query')) allowPartial = route.request().postDataJSON().allowPartial
      await route.fulfill({ json: { data } })
    })
    await spaNavigate(page)('/admin/logs')
    await expect(page.getByRole('heading', { name: 'Logs', exact: true })).toBeVisible()
    await expect(page.getByText(/^worker\.log · ID/)).toBeVisible()
    await expect(page.getByText('Collection issues', { exact: true })).toBeVisible()
    await expect(page.getByText('Web application logs · 1 source', { exact: true })).toBeVisible()
    await expect(page.getByText('worker · 1 source', { exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Source coverage details', exact: true })).toHaveCount(0)
    await expect(page.getByText('Collection host: worker-host', { exact: true })).toBeHidden()
    const coverageTrigger = page.getByRole('button', { name: 'Message coverage details', exact: true })
    expect((await coverageTrigger.locator('..').boundingBox())!.height).toBeLessThanOrEqual(48)
    expect((await page.getByRole('button', { name: 'Source inventory coverage details', exact: true }).locator('..').boundingBox())!.height).toBeLessThanOrEqual(48)
    expect((await page.getByRole('listbox', { name: 'Log messages' }).boundingBox())!.height).toBeGreaterThanOrEqual(180)
    await coverageTrigger.focus()
    await page.keyboard.press('Enter')
    await expect(coverageTrigger).toHaveAttribute('aria-expanded', 'true')
    const issues = page.getByRole('region', { name: 'Message coverage issues' })
    expect((await issues.boundingBox())!.height).toBeLessThanOrEqual(288)
    await expect(issues.getByRole('listitem')).toHaveCount(180)
    await issues.focus()
    await page.keyboard.press('End')
    await expect.poll(() => issues.evaluate(element => element.scrollTop)).toBeGreaterThan(0)
    await coverageTrigger.focus()
    await page.keyboard.press('Enter')
    await expect(coverageTrigger).toHaveAttribute('aria-expanded', 'false')
    await page.getByRole('button', { name: 'Channels', exact: true }).click()
    await page.getByRole('menuitemcheckbox', { name: 'request', exact: true }).click()
    await page.getByRole('menuitemcheckbox', { name: 'security', exact: true }).click()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('button', { name: 'Channels', exact: true })).toHaveText('2 selected')
    expect(new URL(page.url()).searchParams.getAll('channel')).toEqual(['request', 'security'])
    const list = page.getByRole('listbox', { name: 'Log messages' })
    await expect(list.getByRole('option')).toHaveCount(1)
    await list.focus()
    await page.keyboard.press('ArrowDown')
    await expect(page.getByRole('region', { name: 'Record inspector' })).toBeVisible()
    await expect(page.getByLabel('Full log message')).toHaveText(record.message)
    await expect(page.getByLabel('Full log message')).toBeInViewport({ ratio: 1 })
    await page.getByRole('region', { name: 'Record inspector' }).getByText('Record fields', { exact: true }).click()
    await expect(page.getByText('trace-web-worker', { exact: true })).toBeVisible()
    await page.getByRole('region', { name: 'Record inspector' }).getByText('Record fields', { exact: true }).click()
    expect(await page.locator('script').evaluateAll(elements => elements.some(element => element.textContent?.includes('inert record')))).toBe(false)
    await page.getByRole('button', { name: 'Pause', exact: true }).click()
    await expect(list.getByRole('option')).toHaveAttribute('aria-selected', 'true')
    for (const mood of ['warm', 'dark']) {
      await setTheme(page, mood, 'violet')
      await page.screenshot({ path: testInfo.outputPath(`logs-messages-${width}-${mood}.png`) })
      const inspector = page.getByRole('region', { name: 'Record inspector' })
      await inspector.getByRole('button', { name: 'Close inspector' }).scrollIntoViewIfNeeded()
      expect((await inspector.boundingBox())!.height).toBeGreaterThanOrEqual(180)
      await page.screenshot({ path: testInfo.outputPath(`logs-inspector-${width}-${mood}.png`) })
      await inspector.getByRole('button', { name: 'Copy raw record' }).scrollIntoViewIfNeeded()
      await expect(inspector.getByRole('button', { name: 'Copy raw record' })).toBeInViewport()
    }
    await page.getByRole('button', { name: 'SQL query', exact: true }).click()
    await page.getByRole('button', { name: 'Run query', exact: true }).click()
    await expect(page.getByRole('table', { name: 'SQL results' })).toBeVisible()
    expect(allowPartial).toBe(false)
    expect((await page.getByRole('button', { name: 'SQL result coverage details', exact: true }).locator('..').boundingBox())!.height).toBeLessThanOrEqual(48)
    await page.getByRole('checkbox', { name: 'Allow an incomplete snapshot' }).check()
    await page.getByRole('button', { name: 'Run query', exact: true }).click()
    await expect.poll(() => allowPartial).toBe(true)
    await expect(page.getByText(/Snapshot browser-log-snapshot.*Incomplete snapshot/)).toBeVisible()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await page.screenshot({ path: testInfo.outputPath(`logs-${width}.png`) })
  })
}

test('queries multiple retained channels through the authenticated preview API', async ({ asAdmin: page }, testInfo) => {
  const inventoryResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/logs/sources')
  const initialEntries = page.waitForResponse(response => {
    const url = new URL(response.url())
    return url.pathname === '/api/admin/logs/entries' && !url.searchParams.has('channels[]')
  })
  await spaNavigate(page)('/admin/logs')
  const inventory = await inventoryResponse
  expect(inventory.status()).toBe(200)
  expect((await initialEntries).status()).toBe(200)
  await expect(page.getByText('Loading messages…', { exact: true })).toBeHidden()
  const { data: sources } = await inventory.json() as { data: { channels: string[]; coverage: { complete: boolean; reasons: string[] } } }
  expect(sources.channels.length).toBeGreaterThanOrEqual(2)
  const selected = sources.channels.slice(0, 2)
  const entriesResponse = page.waitForResponse(response => {
    const url = new URL(response.url())
    const channels = url.searchParams.getAll('channels[]')
    return url.pathname === '/api/admin/logs/entries'
      && selected.every(channel => channels.includes(channel))
  })
  await page.getByRole('button', { name: 'Channels', exact: true }).click()
  for (const channel of selected) {
    await page.getByRole('menuitemcheckbox', { name: channel, exact: true }).click()
  }
  await page.keyboard.press('Escape')
  const entries = await entriesResponse
  expect(entries.status()).toBe(200)
  const { data: records } = await entries.json() as { data: { items: { id: string; message: string; channel: string | null }[] } }
  expect(records.items.length).toBeGreaterThan(0)
  expect(records.items.every(record => record.channel !== null && selected.includes(record.channel))).toBe(true)
  const firstRow = page.getByRole('listbox', { name: 'Log messages' }).getByRole('option').first()
  const rowId = await firstRow.getAttribute('id')
  const expandedRecord = records.items.find(record => `log-row-${encodeURIComponent(record.id)}` === rowId)
  expect(expandedRecord).toBeDefined()
  await firstRow.click()
  await expect(page.getByLabel('Full log message')).toBeVisible()
  expect(await page.getByLabel('Full log message').textContent()).toBe(expandedRecord!.message)
  const selectedColors: { state: string; foreground: number[]; background: number[]; contrast: number }[] = []
  for (const state of ['hover', 'focus', 'rest']) {
    if (state === 'hover') await firstRow.hover()
    if (state === 'focus') await firstRow.focus()
    if (state === 'rest') await page.mouse.move(1, 1)
    await expectReadable(firstRow)
    const box = (await firstRow.boundingBox())!
    const [background] = await paintedPixels(page, [{ x: box.x + 122, y: box.y + box.height / 2 }])
    const foreground = await textColor(firstRow)
    const light = luminance(foreground)
    const dark = luminance(background)
    const contrast = (Math.max(light, dark) + 0.05) / (Math.min(light, dark) + 0.05)
    expect(contrast).toBeGreaterThanOrEqual(4.5)
    selectedColors.push({ state, foreground, background, contrast })
  }
  await testInfo.attach('selected-row-colors', { body: JSON.stringify(selectedColors), contentType: 'application/json' })
  await page.screenshot({ path: testInfo.outputPath('logs-actual-expanded.png') })
  await page.getByRole('button', { name: 'Close inspector' }).click()
  await expect(page.getByRole('listbox', { name: 'Log messages' })).toBeFocused()

  for (const trigger of await page.getByRole('button', { name: /coverage details$/ }).all()) {
    expect((await trigger.locator('..').boundingBox())!.height).toBeLessThanOrEqual(48)
  }
  await testInfo.attach('real-coverage-count', { body: JSON.stringify({ sourceIssues: sources.coverage.reasons.length }), contentType: 'application/json' })
  const actualColors = await page.getByRole('listbox', { name: 'Log messages' }).getByRole('option').evaluateAll(rows => rows.map(row => ({ level: (row as HTMLElement).dataset.level, background: getComputedStyle(row).backgroundColor })))
  const rootTheme = await page.locator('html').evaluate(element => ({ mood: (element as HTMLElement).dataset.theme, background: getComputedStyle(element).getPropertyValue('--color-background').trim(), info: getComputedStyle(element).getPropertyValue('--color-info').trim() }))
  await testInfo.attach('actual-level-colors', { body: JSON.stringify({ theme: rootTheme, rows: actualColors }), contentType: 'application/json' })
  await page.screenshot({ path: testInfo.outputPath('logs-actual-coverage.png') })
  await page.getByRole('button', { name: 'SQL query', exact: true }).click()
  await page.getByRole('checkbox', { name: 'Allow an incomplete snapshot' }).check()
  const sqlResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/admin/logs/query')
  await page.getByRole('button', { name: 'Run query', exact: true }).click()
  const sql = await sqlResponse
  expect(sql.request().postDataJSON().filter.channels).toEqual(selected)
  expect(sql.status()).toBe(200)
  await expect(page.getByRole('table', { name: 'SQL results' })).toBeVisible()
})


test('severity backgrounds stay subtle and selected rows readable in light and dark', async ({ asAdmin: page }, testInfo) => {
  const levels = ['unknown', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']
  const items = levels.map((level, index) => ({ ...record, id: `severity-${level}`, level, timestamp: `2026-11-20T12:34:${String(59 - index).padStart(2, '0')}Z`, message: `${level} message with readable body text` }))
  await page.route('**/api/admin/logs/**', route => {
    const url = new URL(route.request().url())
    const data = url.pathname.endsWith('/sources') ? { items: [], nodes: [], channels: [], coverage: { complete: true, reasons: [] } }
      : url.pathname.includes('/entries/') ? items.find(item => url.pathname.endsWith(item.id)) ?? record
      : { items, nextCursor: null, snapshot, coverage: { complete: true, reasons: [] } }
    return route.fulfill({ json: { data } })
  })
  await spaNavigate(page)('/admin/logs')
  const rows = page.getByRole('listbox', { name: 'Log messages' }).getByRole('option')
  await expect(rows).toHaveCount(levels.length)
  for (const mood of ['warm', 'dark']) {
    await setTheme(page, mood, 'violet')
    const backgrounds = await rows.evaluateAll(elements => elements.map(element => getComputedStyle(element).backgroundColor))
    expect(new Set(backgrounds).size).toBeGreaterThanOrEqual(7)
    expect(backgrounds[0]).not.toBe(backgrounds[1])
    const debugColor = await rows.nth(1).evaluate(element => {
      const canvas = document.createElement('canvas')
      const context = canvas.getContext('2d')!
      context.fillStyle = getComputedStyle(element).backgroundColor
      context.fillRect(0, 0, 1, 1)
      return [...context.getImageData(0, 0, 1, 1).data]
    })
    expect(debugColor[2]).toBeGreaterThan(debugColor[0])
    const points = await Promise.all([0, 1, 2].map(async index => {
      const box = (await rows.nth(index).boundingBox())!
      return { x: box.x + 122, y: box.y + box.height / 2 }
    }))
    const [unknownPixel, debugPixel, infoPixel] = await paintedPixels(page, points)
    const debugChroma = debugPixel[2] - debugPixel[0]
    const unknownChroma = unknownPixel[2] - unknownPixel[0]
    expect(debugChroma - unknownChroma).toBeGreaterThanOrEqual(12)
    expect(infoPixel[2] - infoPixel[0]).toBeGreaterThan(debugChroma)
    await testInfo.attach(`painted-level-colors-${mood}`, { body: JSON.stringify({ unknownPixel, debugPixel, infoPixel }), contentType: 'application/json' })
    if (mood === 'dark') {
      expect(debugChroma).toBeGreaterThanOrEqual(12)
      expect(Math.max(...unknownPixel) - Math.min(...unknownPixel)).toBeLessThanOrEqual(3)
      expect(await page.locator('html').evaluate(element => getComputedStyle(element).getPropertyValue('--color-background').trim())).toBe('#000000')
    }
    for (const row of await rows.all()) await expectReadable(row)
    for (const level of ['critical', 'emergency']) {
      const label = rows.filter({ hasText: `${level} message` }).locator(':scope > span').nth(1)
      await expect(label).toHaveText(level)
      expect(await label.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true)
    }
    await page.screenshot({ path: testInfo.outputPath(`log-severity-${mood}.png`) })
    const error = rows.filter({ hasText: 'error message' })
    await error.click()
    await expect(error).toHaveAttribute('aria-selected', 'true')
    await expectReadable(error)
    await error.focus()
    await expect(error).toBeFocused()
    await page.screenshot({ path: testInfo.outputPath(`log-severity-selected-${mood}.png`) })
    await page.getByRole('button', { name: 'Close inspector' }).click()
  }
  await page.setViewportSize({ width: 390, height: 900 })
  for (const level of ['critical', 'emergency']) {
    const label = rows.filter({ hasText: `${level} message` }).locator(':scope > span').nth(1)
    await expect(label).toHaveText(level)
    expect(await label.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true)
  }
  await page.screenshot({ path: testInfo.outputPath('log-severity-mobile.png') })
})
