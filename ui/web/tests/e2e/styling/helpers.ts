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
