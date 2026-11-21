import { test, expect, spaNavigate } from '../fixtures'
import { setTheme } from '../helpers/theme-utils'
import type { Span } from '../../../src/features/admin/api/debug-api'

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

for (const width of [1280, 390]) {
  test(`search and inspect retained trace at ${width}px`, async ({ asAdmin: page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 })
    await page.route('**/api/debug/spans*', route => route.fulfill({ json: [child, span()] }))
    await spaNavigate(page)('/admin?tab=diagnostics')
    const section = page.getByRole('region', { name: 'Recent spans', exact: true })
    await expect(section.getByRole('listbox', { name: 'Spans', exact: true })).toBeVisible()
    await section.getByRole('textbox', { name: 'Search spans', exact: true }).fill('catalog.baander.app')
    const results = section.getByRole('listbox', { name: 'Spans', exact: true })
    await expect(results.getByRole('option')).toHaveCount(1)
    await results.focus()
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Enter')
    const inspector = section.getByRole('region', { name: 'Span inspector', exact: true })
    await expect(inspector).toBeVisible()
    await expect(inspector.getByText('Catalog unavailable', { exact: true })).toBeVisible()
    await expect(inspector.getByText(child.attributes['debug.message'] as string, { exact: true })).toBeVisible()
    await expect(inspector.getByText('/app/src/Catalog/Lookup.php', { exact: false })).toBeVisible()
    const waterfall = section.getByRole('listbox', { name: 'Trace waterfall', exact: true })
    await expect(waterfall.getByRole('option')).toHaveCount(2)
    await waterfall.getByRole('option', { name: /GET api_albums_index/ }).click()
    await expect(inspector.getByText('00f067aa0ba902b7', { exact: true })).toBeVisible()
    expect(await page.evaluate(() => 'spanInspectorInjected' in window)).toBe(false)

    for (const mood of ['warm', 'dark']) {
      await setTheme(page, mood, 'violet')
      await section.scrollIntoViewIfNeeded()
      const selectedBackground = await waterfall.getByRole('option', { selected: true }).evaluate(element => getComputedStyle(element).backgroundColor)
      const unselectedBackground = await waterfall.getByRole('option', { selected: false }).evaluate(element => getComputedStyle(element).backgroundColor)
      expect(selectedBackground).not.toBe(unselectedBackground)
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
      await page.screenshot({ path: testInfo.outputPath(`spans-${width}-${mood}.png`), fullPage: true })
    }
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
  await spaNavigate(page)('/admin?tab=diagnostics')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  await section.getByRole('listbox', { name: 'Spans', exact: true }).getByRole('option', { name: /catalog lookup/ }).click()
  const inspector = section.getByRole('region', { name: 'Span inspector', exact: true })
  await expect(inspector).toBeVisible()
  await section.getByRole('button', { name: 'Clear', exact: true }).click()
  await expect(section.getByText('Failed to clear spans.', { exact: true })).toBeVisible()
  await expect(inspector).toBeVisible()

  failRefresh = true
  await section.getByRole('button', { name: 'Refresh', exact: true }).click()
  await expect(section.getByText(/Failed to (load|refresh) spans/)).toBeVisible()
  await expect(inspector).toBeVisible()
  failRefresh = false
  retained = [span()]
  await section.getByRole('button', { name: 'Refresh', exact: true }).click()
  await expect(section.getByText(/no longer retained/i)).toBeVisible()
  await section.getByRole('listbox', { name: 'Spans', exact: true }).getByRole('option').click()
  failClear = false
  await section.getByRole('button', { name: 'Clear', exact: true }).click()
  await expect(section.getByText('No spans recorded.', { exact: true })).toBeVisible()
  await expect(inspector).toHaveCount(0)
})

test('large retained buffers remain keyboard navigable', async ({ asAdmin: page }) => {
  const retained = Array.from({ length: 500 }, (_, index) => span({
    span_id: index.toString(16).padStart(16, '0'),
    operation_name: `retained request ${index}`,
    start_time_us: START_TIME + index * 1_000,
  }))
  await page.route('**/api/debug/spans*', route => route.fulfill({ json: retained }))
  await spaNavigate(page)('/admin?tab=diagnostics')
  const section = page.getByRole('region', { name: 'Recent spans', exact: true })
  const results = section.getByRole('listbox', { name: 'Spans', exact: true })
  await expect(results.getByRole('option').first()).toBeVisible()
  expect(await results.getByRole('option').count()).toBeLessThan(500)
  await results.focus()
  await page.keyboard.press('End')
  await page.keyboard.press('Enter')
  await expect(section.getByRole('region', { name: 'Span inspector' }).getByText('retained request 0', { exact: true })).toBeVisible()
  const waterfall = section.getByRole('listbox', { name: 'Trace waterfall', exact: true })
  await expect(waterfall.getByRole('option').first()).toBeVisible()
  expect(await waterfall.getByRole('option').count()).toBeLessThan(500)
  await waterfall.focus()
  await page.keyboard.press('End')
  await page.keyboard.press('Enter')
  await expect(section.getByRole('region', { name: 'Span inspector' }).getByText('retained request 499', { exact: true })).toBeVisible()
  await expect(waterfall.getByRole('option', { selected: true })).toHaveAttribute('aria-posinset', '500')
  await expect(waterfall.getByRole('option', { selected: true }).getByText('+499.0 ms', { exact: true })).toBeVisible()
})
