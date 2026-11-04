import { test, expect, type Page } from '@playwright/test'
import { expectInkInsideClip, NO_TRACKING_SCRIPTS } from './ink-bounds'
import { TEST_STRINGS, TEXT_ROLES } from './strings'

/** A font file of a family that only Thai, Burmese, Korean or CJK text needs. */
const NON_LATIN_FONT_FILE = /\/noto-sans-(?:thai|myanmar|sc|tc|jp|kr)-[^/]*\.woff2(?:\?|$)/
const CJK_FAMILIES = ['Noto Sans SC Variable', 'Noto Sans TC Variable', 'Noto Sans JP Variable', 'Noto Sans KR Variable']
/** The families the app bundles, as the font files name themselves; a variable font may add its default instance. */
const BUNDLED_FAMILY = /^(?:Geist|Geist Mono|Noto Sans(?: Mono| Thai| Myanmar| SC| TC| JP| KR)?)(?: Thin)?$/

// WebKit cannot map a host name to the loopback, so its fixture requests are answered from the loopback server.
test.beforeEach(async ({ context, baseURL }, testInfo) => {
  if (testInfo.project.name !== 'webkit') return
  const fixture = new URL(baseURL!)
  await context.route((url) => url.host === fixture.host, async (route) => {
    const target = new URL(route.request().url())
    target.hostname = '127.0.0.1'
    await route.fulfill({ response: await route.fetch({ url: target.href }) })
  })
})

async function openFixture(page: Page, path = '/') {
  await page.goto(path)
  await expect(page.locator('h1')).toBeVisible()
  await page.evaluate(() => document.fonts.ready.then(() => undefined))
}

interface TextStyle {
  readonly text: string
  readonly lang: string | null
  readonly fontSize: number
  readonly lineHeight: number
  readonly letterSpacing: string
  readonly fontWeight: string
  readonly fontFamily: string
}

/** The computed type of every element under `selector` that holds text of its own. */
function textStyles(page: Page, selector = 'main'): Promise<TextStyle[]> {
  return page.locator(selector).first().evaluate((root) => [root, ...root.querySelectorAll('*')].flatMap((element) => {
    const text = [...element.childNodes]
      .filter((node) => node.nodeType === Node.TEXT_NODE)
      .map((node) => node.textContent ?? '')
      .join('')
      .trim()
    if (text === '') return []
    const style = getComputedStyle(element)
    return [{
      text,
      lang: element.closest('[lang]')?.getAttribute('lang') ?? null,
      fontSize: Number.parseFloat(style.fontSize),
      lineHeight: Number.parseFloat(style.lineHeight),
      letterSpacing: style.letterSpacing,
      fontWeight: style.fontWeight,
      fontFamily: style.fontFamily,
    }]
  }))
}

function styleOf(page: Page, testId: string): Promise<TextStyle> {
  return textStyles(page, `[data-testid="${testId}"]`).then(([style]) => style)
}

function letterSpacing(style: TextStyle): number {
  return style.letterSpacing === 'normal' ? 0 : Number.parseFloat(style.letterSpacing)
}

function firstCjkFamily(fontFamily: string): string | undefined {
  return fontFamily.split(',').map((family) => family.trim().replace(/^"|"$/g, '')).find((family) => CJK_FAMILIES.includes(family))
}

test('Thai, Burmese, Hangul and CJK text has no letter-spacing, and Latin labels keep theirs', async ({ page }) => {
  await openFixture(page)
  const styles = await textStyles(page)
  const scriptText = styles.filter(({ text }) => NO_TRACKING_SCRIPTS.test(text))
  expect(scriptText.length).toBeGreaterThan(TEXT_ROLES.length * 7)
  expect(scriptText.filter((style) => letterSpacing(style) !== 0)).toEqual([])

  const latinLabel = await styleOf(page, 'label-latin')
  expect(letterSpacing(latinLabel)).toBeCloseTo(0.12 * latinLabel.fontSize, 2)
  expect(letterSpacing(await styleOf(page, 'label-thai'))).toBe(0)
  for (const role of ['hero', 'title-lg', 'title', 'label'] as const) {
    expect(letterSpacing(await styleOf(page, `${role}-en`)), `${role} keeps its tracking for Latin text`).not.toBe(0)
    expect(letterSpacing(await styleOf(page, `${role}-mix`)), `${role} drops tracking for mixed text`).toBe(0)
  }
})

test('wholly Burmese text renders 2px larger with line-height 2.0; mixed Burmese keeps the role size', async ({ page }) => {
  await openFixture(page)
  const burmese = await styleOf(page, 'burmese-body')
  expect(burmese.fontSize).toBe(17)
  expect(burmese.lineHeight / burmese.fontSize).toBeCloseTo(2, 2)
  const mixed = await styleOf(page, 'mixed-burmese-body')
  expect(mixed.fontSize).toBe(15)
  expect(mixed.lineHeight / mixed.fontSize).toBeCloseTo(2, 2)
  expect(letterSpacing(mixed)).toBe(0)

  for (const role of TEXT_ROLES) {
    const latin = await styleOf(page, `${role}-en`)
    expect((await styleOf(page, `${role}-my`)).fontSize, `wholly Burmese ${role}`).toBe(latin.fontSize + 2)
    expect((await styleOf(page, `${role}-mix`)).fontSize, `mixed ${role}`).toBe(latin.fontSize)
  }
})

test('song, column-browser, badge and label text keeps its glyph ink inside its clipping box', async ({ page }) => {
  await openFixture(page)
  const songList = page.getByTestId('song-list')
  const columnBrowser = page.getByTestId('column-browser')
  await expectInkInsideClip(songList, 'song rows')
  await expectInkInsideClip(columnBrowser, 'column-browser rows')
  await expectInkInsideClip(page.getByTestId('badges-and-labels'), 'badges and labels')

  const rowHeights = await songList.evaluate((list) => [...list.children].map((row) => row.getBoundingClientRect().height))
  expect(rowHeights).toEqual(Array(rowHeights.length).fill(32))
  const optionHeights = await columnBrowser.getByRole('option').evaluateAll((options) => options.map((option) => option.getBoundingClientRect().height))
  expect(optionHeights.length).toBeGreaterThan(1)
  expect(optionHeights).toEqual(Array(optionHeights.length).fill(32))
})

test('a page with only Latin text requests no Thai, Burmese, Korean or CJK font', async ({ page }) => {
  const fonts: string[] = []
  page.on('request', (request) => {
    if (request.resourceType() === 'font' || /\.woff2(?:\?|$)/.test(request.url())) fonts.push(request.url())
  })
  await openFixture(page, '/latin.html')
  await expect(page.getByRole('dialog', { name: 'Keyboard Shortcuts' })).toBeVisible()
  await page.evaluate(() => document.fonts.ready.then(() => undefined))

  expect(fonts.some((url) => /\/geist-latin-/.test(url)), 'the Latin page loads Geist').toBe(true)
  expect(fonts.filter((url) => NON_LATIN_FONT_FILE.test(url))).toEqual([])
  const weights = (await textStyles(page, 'body')).map(({ fontWeight }) => fontWeight)
  expect(weights.filter((weight) => !['400', '500', '600', '700'].includes(weight))).toEqual([])
})

test('the element language puts the matching Han glyph family first', async ({ page }) => {
  await openFixture(page)
  const expected = { jp: 'Noto Sans JP Variable', sc: 'Noto Sans SC Variable', tc: 'Noto Sans TC Variable', ko: 'Noto Sans KR Variable' }
  for (const role of TEXT_ROLES) {
    for (const [key, family] of Object.entries(expected)) {
      expect(firstCjkFamily((await styleOf(page, `${role}-${key}`)).fontFamily), `${role} ${key}`).toBe(family)
    }
  }

  const rows = (await textStyles(page, '[data-testid="song-list"]'))
    .map(({ text, lang, fontFamily }) => ({ text, lang, family: firstCjkFamily(fontFamily) }))
  expect(rows).toEqual(expect.arrayContaining([
    { text: '直', lang: 'ja', family: 'Noto Sans JP Variable' },
    { text: '坂本龍一', lang: 'en', family: 'Noto Sans SC Variable' },
    { text: '周杰倫', lang: 'en', family: 'Noto Sans SC Variable' },
    { text: '続きを見る', lang: 'ja', family: 'Noto Sans JP Variable' },
  ]))
})

test('a stored language changes neither tracking nor size; only the characters do', async ({ page }) => {
  await openFixture(page)
  const latin = await styleOf(page, 'latin-label-stored-ja')
  expect(latin.lang).toBe('ja')
  expect(letterSpacing(latin)).toBeCloseTo(0.12 * latin.fontSize, 2)
  const mixed = await styleOf(page, 'mixed-burmese-stored-my')
  expect(mixed.lang).toBe('my')
  expect(mixed.fontSize).toBe(15)
  const thai = await styleOf(page, 'thai-body-stored-ja')
  expect(thai.lang).toBe('ja')
  expect(thai.lineHeight / thai.fontSize).toBeCloseTo(1.8, 2)
})

test('every text weight is 400, 500, 600 or 700', async ({ page }) => {
  await openFixture(page)
  const weights = new Set((await textStyles(page)).map(({ fontWeight }) => fontWeight))
  expect([...weights].filter((weight) => !['400', '500', '600', '700'].includes(weight))).toEqual([])
})

test('every fixture element renders with bundled Geist and Noto faces', async ({ page, browserName }, testInfo) => {
  await openFixture(page)
  await page.screenshot({ path: testInfo.outputPath(`typography-${browserName}.png`), fullPage: true })

  if (browserName === 'chromium') {
    const session = await page.context().newCDPSession(page)
    await session.send('DOM.enable')
    await session.send('CSS.enable')
    const { root } = await session.send('DOM.getDocument', { depth: -1 })
    const { nodeIds } = await session.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: 'main, main *' })
    const used: { nodeId: number; familyName: string; isCustomFont: boolean }[] = []
    for (const nodeId of nodeIds) {
      const { fonts } = await session.send('CSS.getPlatformFontsForNode', { nodeId })
      used.push(...fonts.map(({ familyName, isCustomFont }) => ({ nodeId, familyName, isCustomFont })))
    }
    expect(new Set(used.map(({ nodeId }) => nodeId)).size).toBeGreaterThan(TEXT_ROLES.length * TEST_STRINGS.length)
    expect(used.filter(({ familyName, isCustomFont }) => !isCustomFont || !BUNDLED_FAMILY.test(familyName))).toEqual([])
  }

  // Firefox and WebKit cannot report the faces a node used. In every browser, each character of each
  // role and test string must fall to a bundled face in the element's own family order, and that face
  // must be loaded at the element's weight and size.
  const missing = await page.evaluate(({ roles, strings }) => {
    const faces: { family: string; ranges: [number, number][] }[] = []
    document.fonts.forEach((face) => {
      faces.push({
        family: face.family.replace(/^"|"$/g, ''),
        ranges: face.unicodeRange.split(',').map((part) => {
          const [start, end = start] = part.trim().replace(/^u\+/i, '').split('-')
          return [Number.parseInt(start.replace(/\?/g, '0'), 16), Number.parseInt(end.replace(/\?/g, 'F'), 16)]
        }),
      })
    })
    return roles.flatMap((role) => strings.flatMap((key) => {
      const element = document.querySelector(`[data-testid="${role}-${key}"]`)!
      const style = getComputedStyle(element)
      const stack = style.fontFamily.split(',').map((family) => family.trim().replace(/^"|"$/g, ''))
      const text = style.textTransform === 'uppercase' ? element.textContent!.toUpperCase() : element.textContent!
      return [...new Set(text.replace(/\s/g, ''))].flatMap((character) => {
        const code = character.codePointAt(0)!
        const family = stack.find((name) => faces.some((face) => face.family === name
          && face.ranges.some(([start, end]) => code >= start && code <= end)))
        if (family === undefined) return [`${role}-${key}: ${character} has no bundled face`]
        return document.fonts.check(`${style.fontStyle} ${style.fontWeight} ${style.fontSize} "${family}"`, character)
          ? []
          : [`${role}-${key}: ${character} in ${family} is not loaded`]
      })
    }))
  }, { roles: [...TEXT_ROLES], strings: TEST_STRINGS.map(({ key }) => key) })
  expect(missing).toEqual([])
})
