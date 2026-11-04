import { expect, type Locator } from '@playwright/test'

/** Thai, Burmese, Hangul and CJK characters, whose text must never carry tracking. */
export const NO_TRACKING_SCRIPTS = /[\p{Script=Thai}\p{Script=Myanmar}\p{Script=Hangul}\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}]/u

export interface ClippedInk {
  readonly text: string
  readonly inkTop: number
  readonly inkBottom: number
  readonly clipTop: number
  readonly clipBottom: number
}

export interface InkReport {
  /** How many text runs were measured against a clipping box. */
  readonly checked: number
  readonly clipped: ClippedInk[]
}

/**
 * Runs in the page: measures the glyph ink of every visible text run under `root`
 * whose text matches `filter` (all runs when it is null) and reports the runs whose
 * ink leaves the padding box of their nearest clipping ancestor vertically. Ink
 * overflow is not scrollable overflow, so scroll sizes cannot show it; the ink
 * comes from canvas text metrics at the run's computed font, placed at the
 * baselines of zero-size inline-block probes set before and after the run.
 * Horizontal overflow is left alone, since rows truncate long text on purpose.
 * The function is self-contained so Playwright can send it to the page.
 */
export async function measureInkClipping(root: Element, filter: string | null): Promise<InkReport> {
  const TOLERANCE = 0.5
  const pattern = filter === null ? null : new RegExp(filter, 'u')
  const context = document.createElement('canvas').getContext('2d')!

  function clippingAncestor(element: Element): Element | null {
    for (let current: Element | null = element; current && current !== document.documentElement; current = current.parentElement) {
      const style = getComputedStyle(current)
      if (style.overflowY !== 'visible' || /\b(paint|strict|content)\b/.test(style.contain)) {
        return current
      }
    }

    return null
  }

  function probe(): HTMLSpanElement {
    const element = document.createElement('span')
    element.style.cssText = 'display:inline-block;width:0;height:0;margin:0;padding:0;border:0'

    return element
  }

  /** The baselines of the run's first and last line. */
  function baselines(node: Text): [number, number] {
    const parent = node.parentElement!
    // Text directly in a flex or grid container forms an anonymous item, which a probe sibling would leave.
    const wrapper = /flex|grid/.test(getComputedStyle(parent).display) ? document.createElement('span') : null
    if (wrapper) {
      parent.insertBefore(wrapper, node)
      wrapper.appendChild(node)
    }
    const before = probe(), after = probe()
    node.before(before)
    node.after(after)
    const result: [number, number] = [before.getBoundingClientRect().bottom, after.getBoundingClientRect().bottom]
    before.remove()
    after.remove()
    if (wrapper) {
      wrapper.before(node)
      wrapper.remove()
    }

    return result
  }

  await document.fonts.ready
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  const runs: Text[] = []
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node.textContent ?? ''
    if (text.trim() !== '' && (pattern === null || pattern.test(text))) {
      runs.push(node as Text)
    }
  }

  let checked = 0
  const clipped: ClippedInk[] = []
  for (const node of runs) {
    const parent = node.parentElement!
    const style = getComputedStyle(parent)
    const clip = clippingAncestor(parent)
    if (style.visibility !== 'visible' || parent.getClientRects().length === 0 || clip === null) {
      continue
    }

    const font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`
    const text = style.textTransform === 'uppercase' ? node.data.toUpperCase() : node.data
    await document.fonts.load(font, text)
    context.font = font
    const metrics = context.measureText(text)
    const [firstBaseline, lastBaseline] = baselines(node)
    const box = clip.getBoundingClientRect()
    const clipTop = box.top + clip.clientTop
    const clipBottom = clipTop + clip.clientHeight
    const inkTop = firstBaseline - metrics.actualBoundingBoxAscent
    const inkBottom = lastBaseline + metrics.actualBoundingBoxDescent
    checked++
    if (inkTop < clipTop - TOLERANCE || inkBottom > clipBottom + TOLERANCE) {
      clipped.push({ text: text.trim(), inkTop, inkBottom, clipTop, clipBottom })
    }
  }

  return { checked, clipped }
}

/** Fails when any measured text run in the surface has glyph ink outside its nearest clipping box. */
export async function expectInkInsideClip(surface: Locator, name: string, pattern?: RegExp) {
  const report = await surface.evaluate(measureInkClipping, pattern?.source ?? null)
  expect(report.checked, `${name} has text inside a clipping box`).toBeGreaterThan(0)
  expect(report.clipped, `${name} keeps every glyph inside its clipping box`).toEqual([])
}
