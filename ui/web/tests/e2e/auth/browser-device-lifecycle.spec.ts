import { test, expect, authenticate } from '../fixtures'
import { ownBrowserContext } from '../helpers/browser-device-lifecycle'
import { authenticatedGet } from '../live-updates/capacity-fixtures'

test('browser contexts own distinct device tokens that survive reload', async ({ asAdmin: page, browser, baseURL, ownedDevice }, testInfo) => {
  const secondContext = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  try {
    const secondId = await ownBrowserContext(secondContext, baseURL)
    const secondPage = await secondContext.newPage()
    await authenticate(secondPage)
    expect(secondId).not.toBe(ownedDevice)
    await expect.poll(async () => {
      const response = await authenticatedGet<{ data: { deviceId: string }[] }>(page, '/api/devices')
      return response.data.map(device => device.deviceId)
    }).toEqual(expect.arrayContaining([ownedDevice, secondId]))
    expect(await page.evaluate(() => localStorage.getItem('baander-device-id'))).toBe(ownedDevice)
    expect(await secondPage.evaluate(() => localStorage.getItem('baander-device-id'))).toBe(secondId)
    await page.reload()
    expect(await page.evaluate(() => localStorage.getItem('baander-device-id'))).toBe(ownedDevice)
    await testInfo.attach('owned-browser-device-tokens', {
      body: JSON.stringify([ownedDevice, secondId]), contentType: 'application/json',
    })
  } finally {
    await secondContext.close()
  }
})

test.describe('Failure cleanup acceptance', () => {
  test.skip(process.env.E2E_DEVICE_CLEANUP_FORCE_FAILURE !== '1', 'Explicit teardown failure acceptance check')

  test('cleanup runs after a failed authenticated test', async ({ asAdmin: page, ownedDevice }, testInfo) => {
    await expect.poll(async () => {
      const response = await authenticatedGet<{ data: { deviceId: string }[] }>(page, '/api/devices')
      return response.data.map(device => device.deviceId)
    }).toContain(ownedDevice)
    await testInfo.attach('owned-browser-device-tokens', {
      body: JSON.stringify([ownedDevice]), contentType: 'application/json',
    })
    await expect(page).toHaveURL(/\/music$/)
    throw new Error('Intentional failure after browser device registration')
  })
})
