import { expect, type Locator, type Page } from '@playwright/test'

/** Check the rendered foreground against the composited ancestor backgrounds. */
export async function expectReadable(locator: Locator, minimum = 4.5) {
  const contrast = await locator.evaluate(async (element) => {
    const ancestors: Element[] = []
    for (let node: Element | null = element; node; node = node.parentElement) ancestors.push(node)
    await Promise.all(ancestors.flatMap(node => node.getAnimations())
      .filter(animation => animation.playState === 'running' && animation.effect?.getComputedTiming().iterations !== Infinity)
      .map(animation => animation.finished.catch(() => undefined)))
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const context = canvas.getContext('2d')!
    const rgba = (value: string) => {
      context.clearRect(0, 0, 1, 1)
      context.fillStyle = value
      context.fillRect(0, 0, 1, 1)
      const [r, g, b, a] = context.getImageData(0, 0, 1, 1).data
      return [r, g, b, a / 255]
    }
    const composite = (front: number[], back: number[]) => {
      const alpha = front[3] + back[3] * (1 - front[3])
      if (alpha === 0) return [0, 0, 0, 0]
      return [
        ...front.slice(0, 3).map((channel, index) =>
          (channel * front[3] + back[index] * back[3] * (1 - front[3])) / alpha),
        alpha,
      ]
    }
    let foreground = rgba(getComputedStyle(element).color)
    let background = [0, 0, 0, 0]
    for (const ancestor of ancestors) {
      const style = getComputedStyle(ancestor)
      const surface = rgba(style.backgroundColor)
      foreground = composite(foreground, surface)
      background = composite(background, surface)
      foreground[3] *= Number(style.opacity)
      background[3] *= Number(style.opacity)
    }
    const canvasBackground = [255, 255, 255, 1]
    foreground = composite(foreground, canvasBackground)
    background = composite(background, canvasBackground)
    const luminance = (channels: number[]) => channels.slice(0, 3).map((channel) => {
      const value = channel / 255
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
    }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0)
    const a = luminance(foreground)
    const b = luminance(background)
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
  })
  expect(contrast).toBeGreaterThanOrEqual(minimum)
}

export async function expectContained(locator: Locator) {
  await expect(locator).toBeVisible()
  const geometry = await locator.evaluate((element) => {
    const bounds = element.getBoundingClientRect()
    const parent = element.parentElement!.getBoundingClientRect()
    return { left: bounds.left, right: bounds.right, parentLeft: parent.left, parentRight: parent.right, viewport: innerWidth }
  })
  expect(geometry.left).toBeGreaterThanOrEqual(Math.max(0, geometry.parentLeft) - 1)
  expect(geometry.right).toBeLessThanOrEqual(Math.min(geometry.viewport, geometry.parentRight) + 1)
}

export async function expectNoPageOverflow(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)
  expect(overflow).toBeLessThanOrEqual(1)
}

/** The painted colour of each viewport point, as red, green and blue, read from one screenshot. */
export async function paintedPixels(page: Page, points: { x: number; y: number }[]): Promise<number[][]> {
  const screenshot = await page.screenshot({ scale: 'css' })
  return page.evaluate(async ({ encoded, points }) => {
    const image = new Image()
    image.src = `data:image/png;base64,${encoded}`
    await image.decode()
    const canvas = document.createElement('canvas')
    canvas.width = image.width
    canvas.height = image.height
    const context = canvas.getContext('2d')!
    context.drawImage(image, 0, 0)
    return points.map(({ x, y }) => [...context.getImageData(Math.round(x), Math.round(y), 1, 1).data.slice(0, 3)])
  }, { encoded: screenshot.toString('base64'), points })
}

/** The colour an element's text is drawn in, as red, green and blue. */
export async function textColor(locator: Locator): Promise<number[]> {
  return locator.evaluate((element) => {
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 1
    const context = canvas.getContext('2d')!
    context.fillStyle = getComputedStyle(element).color
    context.fillRect(0, 0, 1, 1)
    return [...context.getImageData(0, 0, 1, 1).data.slice(0, 3)]
  })
}

/** Relative luminance of a colour with channels from 0 to 255. */
export function luminance(color: number[]): number {
  return color.slice(0, 3).map((channel) => {
    const value = channel / 255
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0)
}

export function contrastRatio(first: number[], second: number[]): number {
  const [lighter, darker] = [luminance(first), luminance(second)].sort((a, b) => b - a)
  return (lighter + 0.05) / (darker + 0.05)
}
