import { test, expect, spaNavigate } from '../fixtures'
import { setTheme } from '../helpers/theme-utils'
import type { Span } from '../../../src/features/admin/api/debug-api'
import type { Locator } from '@playwright/test'

const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736'
const START_TIME = 1_795_257_600_000_000

function span(overrides: Partial<Span> = {}): Span {
  return {
    trace_id: TRACE_ID,
    span_id: '00f067aa0ba902b7',
    parent_span_id: null,
    operation_name: 'GET api_albums_index',
    service: 'baander',
    kind: '2',
    start_time_us: START_TIME,
    duration_us: 120_000,
    attributes: { 'http.request.method': 'GET', 'http.response.status_code': 200 },
    status_code: 'Unset',
    status_message: '',
    file_path: null,
    line_number: null,
    ...overrides,
  }
}

const child = span({
  span_id: '00f067aa0ba902b8',
  parent_span_id: '00f067aa0ba902b7',
  operation_name: 'catalog lookup',
  service: 'catalog',
  start_time_us: START_TIME + 10_000,
  duration_us: 65_000,
  attributes: {
    'http.response.status_code': 503,
    'debug.message': '<script>window.spanInspectorInjected = true</script>',
    'debug.long-value': 'long-diagnostic-value-'.repeat(30),
    'debug.host': 'catalog.baander.app',
  },
  status_code: 'Error',
  status_message: 'Catalog unavailable',
  file_path: '/app/src/Catalog/Lookup.php',
  line_number: 42,
})
const branches = Array.from({ length: 8 }, (_, index) => span({
  span_id: `00000000000000c${index}`,
  parent_span_id: child.span_id,
  operation_name: `cache lookup ${index}`,
  service: 'cache',
  start_time_us: START_TIME + 12_000 + index * 5_000,
  duration_us: 4_000,
}))
const sibling = span({ span_id: '00000000000000d0', parent_span_id: span().span_id, operation_name: 'serialize response', start_time_us: START_TIME + 80_000, duration_us: 40_000 })
const independent = span({ trace_id: '7bf92f3577b34da6a3ce929d0e0e4737', span_id: '00f067aa0ba902b9', operation_name: 'POST api_library_scan', start_time_us: START_TIME + 60_000, duration_us: 90_000 })

async function expectTiming(row: Locator, offset: number, duration: number) {
  const track = (await row.locator('[data-timeline-track]').boundingBox())!
  const bar = (await row.locator('[data-timeline-bar]').boundingBox())!
  expect((await row.boundingBox())!.height).toBe(32)
  expect(bar.height).toBeGreaterThanOrEqual(12)
  expect(Math.abs(bar.x - track.x - track.width * offset)).toBeLessThan(2)
  expect(Math.abs(bar.width - track.width * duration)).toBeLessThan(2)
}

async function expectAlignedAxis(timeline: Locator) {
  const axis = (await timeline.locator('[data-timeline-axis]').boundingBox())!
  for (const row of await timeline.locator('[data-span-row]').all()) {
    const track = (await row.locator('[data-timeline-track]').boundingBox())!
    expect(track.x).toBeCloseTo(axis.x, 0)
    expect(track.width).toBeCloseTo(axis.width, 0)
    for (const position of [25, 50, 75]) {
      const tick = (await timeline.locator(`[data-timeline-tick="${position}"]`).boundingBox())!
      const gridline = (await row.locator(`[data-timeline-gridline="${position}"]`).boundingBox())!
      expect(Math.abs(tick.x + tick.width / 2 - gridline.x)).toBeLessThan(2)
    }
  }
}

for (const width of [1280, 390]) {
  test(`search and inspect the trace tree at ${width}px`, async ({ asAdmin: page }, testInfo) => {
    await page.setViewportSize({ width, height: 1000 })
    await page.route('**/api/debug/spans*', route => route.fulfill({ json: [child, span(), ...branches, sibling, independent] }))
    await spaNavigate(page)('/admin/spans')
    const section = page.getByRole('region', { name: 'Recent spans', exact: true })
    const overview = section.getByRole('treegrid', { name: 'Spans', exact: true })
    await expect(overview.locator('[data-timeline-bar]')).toHaveCount(12)
    await expectTiming(overview.getByRole('row', { name: /GET api_albums_index/ }), 0, 0.8)
    await expectTiming(overview.getByRole('row', { name: /catalog lookup/ }), 1 / 15, 65 / 150)
    await section.getByRole('textbox', { name: 'Search spans', exact: true }).fill('catalog.baander.app')
    await expect(overview.locator('[data-span-row]')).toHaveCount(1)
    await overview.getByRole('row', { name: /catalog lookup/ }).click()
    const trace = section.getByRole('treegrid', { name: 'Trace waterfall', exact: true })
    await expect(trace.locator('[data-span-row]')).toHaveCount(11)
    await expect(section.getByRole('textbox', { name: 'Search spans' })).toHaveCount(0)
    await expect(section.getByRole('img', { name: 'Trace overview' })).toBeVisible()
    await expect(section.getByLabel('Retained trace summary')).toContainText('Services3')
    await expect(section.getByLabel('Retained trace summary')).toContainText('Depth3')
    await expect(section.getByLabel('Retained trace summary')).toContainText('Spans11')
    await expectTiming(trace.getByRole('row', { name: /GET api_albums_index/ }), 0, 1)
    await expectTiming(trace.getByRole('row', { name: /catalog lookup/ }), 1 / 12, 65 / 120)
    await expectAlignedAxis(trace)

    const traceWidth = (await trace.boundingBox())!.width
    const selectedRow = trace.getByRole('row', { name: /catalog · catalog lookup/ })
    await selectedRow.locator('[data-timeline-bar]').click()
    const inspector = trace.getByRole('region', { name: 'Span inspector', exact: true })
    await expect(inspector).toBeVisible()
    await expect(inspector.getByText(child.attributes['debug.message'] as string, { exact: true })).toBeVisible()
    expect((await trace.boundingBox())!.width).toBeCloseTo(traceWidth, 0)
    const selectedBounds = (await selectedRow.boundingBox())!
    const inlineBounds = (await inspector.boundingBox())!
    expect(inlineBounds.y).toBeGreaterThanOrEqual(selectedBounds.y + selectedBounds.height)
    const next = trace.getByRole('row', { name: /cache · cache lookup 0/ })
    expect((await next.boundingBox())!.y).toBeGreaterThanOrEqual(inlineBounds.y + inlineBounds.height)
    await inspector.getByText('Span metadata', { exact: true }).click()
    await expect(inspector.getByText('Catalog unavailable', { exact: true })).toBeVisible()
    await expect(inspector.getByText('/app/src/Catalog/Lookup.php:42', { exact: true })).toBeVisible()
    expect(await page.evaluate(() => 'spanInspectorInjected' in window)).toBe(false)
    await inspector.getByRole('button', { name: 'Close inspector' }).click()
    await expect(selectedRow).toBeFocused()

    await trace.getByRole('row', { name: /cache · cache lookup 0/ }).click()
    await trace.getByRole('button', { name: 'Collapse catalog lookup', exact: true }).click()
    await expect(trace.locator('[data-span-row]')).toHaveCount(3)
    await expect(trace.getByRole('region', { name: 'Span inspector' })).toHaveCount(0)
    await expect(selectedRow).toBeFocused()
    await expect(section.getByLabel('Retained trace summary')).toContainText('Spans11')
    await trace.getByRole('button', { name: 'Expand catalog lookup', exact: true }).click()
    await expect(trace.locator('[data-span-row]')).toHaveCount(11)

    const firstTrack = trace.locator('[data-span-row]').first().locator('[data-timeline-track]')
    const fitWidth = (await firstTrack.boundingBox())!.width
    for (const zoom of [2, 4, 8]) {
      await section.getByRole('combobox', { name: 'Timeline zoom' }).click()
      await page.getByRole('option', { name: `${zoom}×`, exact: true }).click()
      expect((await firstTrack.boundingBox())!.width).toBeCloseTo(fitWidth * zoom, 0)
      await expectAlignedAxis(trace)
      await expectTiming(selectedRow, 1 / 12, 65 / 120)
    }
    const labelBefore = (await trace.locator('[data-timeline-label]').first().boundingBox())!
    const axisBefore = (await trace.locator('[data-timeline-axis]').boundingBox())!
    await trace.evaluate(element => { element.scrollLeft = 100 })
    expect(await trace.evaluate(element => element.scrollLeft)).toBe(100)
    expect((await trace.locator('[data-timeline-label]').first().boundingBox())!.x).toBeCloseTo(labelBefore.x, 0)
    expect((await trace.locator('[data-timeline-axis]').boundingBox())!.x).toBeCloseTo(axisBefore.x - 100, 0)
    await section.getByRole('combobox', { name: 'Timeline zoom' }).click()
    await page.getByRole('option', { name: 'Fit', exact: true }).click()
    expect((await firstTrack.boundingBox())!.width).toBeCloseTo(fitWidth, 0)
    expect(await trace.evaluate(element => element.scrollLeft)).toBe(0)

    await selectedRow.click()
    await trace.getByRole('region', { name: 'Span inspector' }).getByText('Attributes', { exact: true }).click()
    for (const mood of ['warm', 'dark']) {
      await setTheme(page, mood, 'violet')
      await section.getByRole('img', { name: 'Trace overview' }).scrollIntoViewIfNeeded()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
      await page.screenshot({ path: testInfo.outputPath(`trace-${width}-${mood}.png`), fullPage: true })
    }
    await section.getByRole('button', { name: 'Back to results' }).click()
    await expect(section.getByRole('textbox', { name: 'Search spans' })).toHaveValue('catalog.baander.app')
  })
}

test('refresh and clear preserve honest selection state', async ({ asAdmin: page }) => {
  let retained = [child, span()]
  let failClear = true
  let failRefresh = false
  await page.route('**/api/debug/spans*', route => {
    if (route.request().method() === 'DELETE') {
      if (failClear) return route.fulfill({ status: 500, json: { message: 'Clear failed' } })
      retained = []
      return route.fulfill({ json: { status: 'cleared' } })
    }
    if (failRefresh) return route.fulfill({ status: 500, json: { message: 'Read failed' } })
    return route.fulfill({ json: retained })
  })
  await spaNavigate(page)('/admin/spans')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  await section.getByRole('treegrid', { name: 'Spans', exact: true }).getByRole('row', { name: /catalog lookup/ }).click()
  await section.getByRole('treegrid', { name: 'Trace waterfall' }).getByRole('row', { name: /catalog lookup/ }).click()
  const inspector = section.getByRole('region', { name: 'Span inspector', exact: true })
  await section.getByRole('button', { name: 'Clear', exact: true }).click()
  await expect(section.getByText('Failed to clear spans.', { exact: true })).toBeVisible()
  await expect(inspector).toBeVisible()
  failRefresh = true
  await section.getByRole('button', { name: 'Refresh', exact: true }).click()
  await expect(section.getByText(/Failed to refresh spans/)).toBeVisible()
  await expect(inspector).toBeVisible()
  failRefresh = false
  retained = [span()]
  await section.getByRole('button', { name: 'Refresh', exact: true }).click()
  await expect(section.getByText(/no longer retained/i)).toBeVisible()
  await expect(section.getByRole('button', { name: 'Back to results' })).toHaveCount(0)
  failClear = false
  await section.getByRole('button', { name: 'Clear', exact: true }).click()
  await expect(section.getByText('No spans recorded.', { exact: true })).toBeVisible()
  await expect(inspector).toHaveCount(0)
})

test('instantaneous spans retain a visible selectable marker', async ({ asAdmin: page }) => {
  const instant = span({ span_id: '00f067aa0ba902b0', operation_name: 'instant request', start_time_us: START_TIME + 120_000, duration_us: 0 })
  await page.route('**/api/debug/spans*', route => route.fulfill({ json: [instant, span()] }))
  await spaNavigate(page)('/admin/spans')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  await section.getByRole('treegrid', { name: 'Spans' }).getByRole('row', { name: /instant request/ }).click()
  const row = section.getByRole('treegrid', { name: 'Trace waterfall' }).getByRole('row', { name: /instant request/ })
  const marker = row.locator('[data-timeline-bar]')
  expect((await marker.boundingBox())!.width).toBeGreaterThanOrEqual(2)
  await expect(row.getByText('0.0 ms', { exact: true })).toBeVisible()
  await marker.click()
  await expect(section.getByRole('region', { name: 'Span inspector' }).getByRole('heading', { name: 'instant request', exact: true })).toBeVisible()
})

function retainedSpans() {
  return Array.from({ length: 500 }, (_, index) => span({
    span_id: index.toString(16).padStart(16, '0'),
    operation_name: `retained request ${index}`,
    start_time_us: START_TIME + index * 1_000,
    attributes: index === 0 ? { 'debug.long-value': 'line of diagnostic context\n'.repeat(50) } : {},
  }))
}

test('large traces measure inline details and remain keyboard navigable', async ({ asAdmin: page }) => {
  await page.route('**/api/debug/spans*', route => route.fulfill({ json: retainedSpans() }))
  await spaNavigate(page)('/admin/spans')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  const results = section.getByRole('treegrid', { name: 'Spans', exact: true })
  await expect(results.locator('[data-span-row]').first()).toBeVisible()
  expect(await results.locator('[data-span-row]').count()).toBeLessThan(500)
  await results.locator('[data-span-row]').first().focus()
  await page.keyboard.press('End')
  await page.keyboard.press('Enter')
  const trace = section.getByRole('treegrid', { name: 'Trace waterfall', exact: true })
  const first = trace.getByRole('row', { name: /retained request 0 ·/ })
  await first.click()
  const inspector = trace.getByRole('region', { name: 'Span inspector' })
  await expect(inspector).toBeVisible()
  const next = trace.getByRole('row', { name: /retained request 1 ·/ })
  await expect(next).toBeAttached()
  await expect.poll(async () => (await next.boundingBox())!.y - ((await inspector.boundingBox())!.y + (await inspector.boundingBox())!.height)).toBeGreaterThanOrEqual(0)
  expect((await next.boundingBox())!.y - ((await inspector.boundingBox())!.y + (await inspector.boundingBox())!.height)).toBeLessThan(4)
  await inspector.getByRole('button', { name: 'Close inspector' }).click()
  await expect(first).toBeFocused()
  await page.keyboard.press('End')
  await page.keyboard.press('Enter')
  await expect(trace.getByRole('region', { name: 'Span inspector' }).getByRole('heading', { name: 'retained request 499', exact: true })).toBeVisible()
  await expectTiming(trace.getByRole('row', { selected: true }), 499 / 619, 120 / 619)
})

test('diagnostics links to the dedicated spans page', async ({ asAdmin: page }) => {
  let reads = 0
  await page.route('**/api/debug/spans*', route => {
    reads++
    return route.fulfill({ json: [span()] })
  })
  await spaNavigate(page)('/admin?tab=diagnostics')
  const link = page.getByRole('link', { name: 'Open spans', exact: true })
  await expect(link).toBeVisible()
  await expect(page.getByRole('region', { name: 'Recent spans', exact: true })).toHaveCount(0)
  expect(reads).toBe(0)
  await link.click()
  await expect(page).toHaveURL(/\/admin\/spans$/)
  await expect(page.getByRole('treegrid', { name: 'Spans', exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('treegrid', { name: 'Spans', exact: true })).toBeVisible()
})

for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 640 }]) {
  test(`dedicated workspace keeps selection anchored at ${viewport.width}px`, async ({ asAdmin: page }) => {
    await page.setViewportSize(viewport)
    const spans = retainedSpans().map((item, index) => index === 240 ? {
      ...item, attributes: { context: 'A long diagnostic line\n'.repeat(50) },
    } : item)
    await page.route('**/api/debug/spans*', route => route.fulfill({ json: spans }))
    await spaNavigate(page)('/admin/spans')
    await expect(page.getByRole('link', { name: 'Spans', exact: true })).toHaveAttribute('aria-current', 'page')
    const workspace = page.getByRole('region', { name: 'Recent spans', exact: true })
    const main = page.getByRole('main')
    const results = workspace.getByRole('treegrid', { name: 'Spans', exact: true })
    await results.locator('[data-span-row]').first().click()
    const trace = workspace.getByRole('treegrid', { name: 'Trace waterfall', exact: true })
    const mainBounds = (await main.boundingBox())!
    const workspaceBounds = (await workspace.boundingBox())!
    const traceBounds = (await trace.boundingBox())!
    expect(workspaceBounds.height).toBeCloseTo(mainBounds.height, 0)
    expect(workspaceBounds.y).toBeCloseTo(mainBounds.y, 0)
    expect(traceBounds.height).toBeGreaterThan(72)
    expect(Math.abs(traceBounds.y + traceBounds.height - workspaceBounds.y - workspaceBounds.height)).toBeLessThanOrEqual(18)
    const outerScroll = () => page.evaluate(() => ({
      document: document.scrollingElement!.scrollTop,
      main: document.querySelector('main')!.scrollTop,
      overflow: document.documentElement.scrollHeight - window.innerHeight,
    }))
    expect(await outerScroll()).toEqual({ document: 0, main: 0, overflow: 0 })
    await trace.evaluate(element => { element.scrollTop = 240 * 32 - 16 })
    const row = trace.getByRole('row', { name: /retained request 240 ·/ })
    await expect(row).toBeVisible()
    const top = (await row.boundingBox())!.y
    const scrollTop = await trace.evaluate(element => element.scrollTop)
    const visibleRow = (await row.boundingBox())!
    expect(visibleRow.y).toBeGreaterThan(traceBounds.y + 40)
    expect(visibleRow.y + visibleRow.height).toBeLessThan(traceBounds.y + traceBounds.height)
    // Use viewport coordinates so the driver does not scroll before the pointer selection.
    await page.mouse.click(visibleRow.x + 100, visibleRow.y + 16)
    await expect(trace.getByRole('region', { name: 'Span inspector' })).toBeVisible()
    await expect.poll(async () => (await row.boundingBox())!.y).toBeCloseTo(top, 0)
    expect(await trace.evaluate(element => element.scrollTop)).toBe(scrollTop)
    expect(await outerScroll()).toEqual({ document: 0, main: 0, overflow: 0 })
    await page.keyboard.press('Escape')
    await expect(row).toBeFocused()
    expect((await row.boundingBox())!.y).toBeCloseTo(top, 0)
    await page.keyboard.press('End')
    await expect(trace.getByRole('row', { name: /retained request 499 ·/ })).toBeFocused()
    expect(await outerScroll()).toEqual({ document: 0, main: 0, overflow: 0 })
  })
}

test('switching inline selection preserves a visible row after scrolling backward', async ({ asAdmin: page }) => {
  await page.setViewportSize({ width: 1280, height: 900 })
  const spans = retainedSpans()
  for (const item of spans.slice(1)) item.parent_span_id = spans[0].span_id
  await page.route('**/api/debug/spans*', route => route.fulfill({ json: spans }))
  await spaNavigate(page)('/admin/spans')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  await section.getByRole('treegrid', { name: 'Spans', exact: true }).locator('[data-span-row]').first().click()
  const trace = section.getByRole('treegrid', { name: 'Trace waterfall', exact: true })
  const closedHeight = await trace.evaluate(element => element.scrollHeight)
  await trace.getByRole('row', { name: /retained request 0 ·/ }).click()
  await expect(trace.getByRole('region', { name: 'Span inspector' })).toBeVisible()
  await trace.evaluate(element => { element.scrollTop = 2500 })
  await expect.poll(() => trace.evaluate(element => element.scrollTop)).toBe(2500)
  await trace.evaluate(element => { element.scrollTop -= 64 })
  const key = await trace.evaluate(element => {
    const bounds = element.getBoundingClientRect()
    return Array.from(element.querySelectorAll<HTMLElement>('[data-span-row]')).find(row => {
      const rowBounds = row.getBoundingClientRect()
      return rowBounds.top > bounds.top + 48 && rowBounds.bottom < bounds.bottom
    })!.dataset.spanRow
  })
  const row = trace.locator(`[data-span-row="${key}"]`)
  const before = (await row.boundingBox())!
  await page.mouse.click(before.x + 100, before.y + 16)
  await expect(row).toHaveAttribute('aria-selected', 'true')
  await expect.poll(async () => (await row.boundingBox())!.y).toBeCloseTo(before.y, 0)
  await expect.poll(async () => {
    const detailHeight = (await trace.locator('[data-span-detail]').boundingBox())!.height
    return Math.abs(await trace.evaluate(element => element.scrollHeight) - closedHeight - detailHeight)
  }).toBeLessThan(2)
  const measuredHeight = await trace.evaluate(element => element.scrollHeight)
  await trace.evaluate(element => { element.scrollTop = 0 })
  await expect(trace.getByRole('row', { name: /retained request 0 ·/ })).toBeVisible()
  expect(await trace.evaluate(element => element.scrollHeight)).toBe(measuredHeight)
  await trace.getByRole('button', { name: 'Collapse retained request 0', exact: true }).click()
  await expect(trace.getByRole('region', { name: 'Span inspector' })).toHaveCount(0)
  await trace.getByRole('button', { name: 'Expand retained request 0', exact: true }).click()
  await expect.poll(() => trace.evaluate(element => element.scrollHeight)).toBe(closedHeight)
  expect(await page.evaluate(() => document.scrollingElement!.scrollTop)).toBe(0)
  expect(await page.getByRole('main').evaluate(element => element.scrollTop)).toBe(0)
})

test('frozen snapshots and live updates preserve scroll and zoom', async ({ asAdmin: page }) => {
  test.setTimeout(70_000)
  let retained = retainedSpans()
  let reads = 0
  await page.route('**/api/debug/spans*', route => {
    reads++
    return route.fulfill({ json: retained })
  })
  await spaNavigate(page)('/admin/spans')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  const results = section.getByRole('treegrid', { name: 'Spans', exact: true })
  await expect(results.locator('[data-span-row]').first()).toBeVisible()
  await section.getByRole('combobox', { name: 'Timeline zoom' }).click()
  await page.getByRole('option', { name: '2×', exact: true }).click()
  await results.evaluate(element => { element.scrollTop = 249 * 32 + 40; element.scrollLeft = 80 })
  const anchor = results.getByRole('row', { name: /retained request 250 ·/ })
  await expect(anchor).toBeVisible()
  const anchorOffset = async () => (await anchor.boundingBox())!.y - (await results.boundingBox())!.y
  const anchorTop = await anchorOffset()
  const scrollTop = await results.evaluate(element => element.scrollTop)
  function arrive(index: number) {
    retained = [...retained.slice(1), span({ span_id: index.toString(16).padStart(16, '0'), operation_name: `retained request ${index}`, start_time_us: START_TIME + index * 1_000 })]
  }
  arrive(500)
  let before = reads
  await expect.poll(() => reads, { timeout: 16_000 }).toBeGreaterThan(before)
  await expect(results.getByRole('row', { name: /retained request 499 ·/ })).toBeAttached()
  expect(await results.evaluate(element => element.scrollTop)).toBe(scrollTop)
  expect(await anchorOffset()).toBeCloseTo(anchorTop, 0)
  await section.getByRole('button', { name: 'Refresh', exact: true }).click()
  await expect(results.getByRole('row', { name: /retained request 500 ·/ })).toBeAttached()
  await expect.poll(anchorOffset).toBeCloseTo(anchorTop, 0)
  await expect(section.getByRole('combobox', { name: 'Timeline zoom' })).toHaveText('2×')
  expect(await results.evaluate(element => element.scrollLeft)).toBe(80)
  await section.getByRole('button', { name: 'Live updates' }).click()
  arrive(501)
  before = reads
  await expect.poll(() => reads, { timeout: 16_000 }).toBeGreaterThan(before)
  await expect(results.getByRole('row', { name: /retained request 501 ·/ })).toBeAttached()
  await expect.poll(anchorOffset).toBeCloseTo(anchorTop, 0)
  await expect(section.getByRole('combobox', { name: 'Timeline zoom' })).toHaveText('2×')
  await section.getByRole('button', { name: 'Live updates' }).click()
  arrive(502)
  before = reads
  await expect.poll(() => reads, { timeout: 16_000 }).toBeGreaterThan(before)
  await expect(results.getByRole('row', { name: /retained request 501 ·/ })).toBeAttached()
  expect(await anchorOffset()).toBeCloseTo(anchorTop, 0)
})
