import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Opens the header disclosure that shows a menu group and returns it once it is open. Above 640px
 * each group has a panel of its own; at phone width one Menu disclosure holds both groups.
 */
export async function openMenuPanel(page: Page, group: 'Product' | 'Resources'): Promise<Locator> {
  const navigation = page.getByRole('navigation', { name: 'Main navigation' });
  const phone = navigation.locator('details.menu-phone');
  const disclosure = await phone.isVisible()
    ? phone
    : navigation.locator('details.menu-panel').filter({ has: page.locator(':scope > summary', { hasText: group }) });
  if (await disclosure.getAttribute('open') === null) await disclosure.locator(':scope > summary').click();
  await expect(disclosure).toHaveAttribute('open', '');
  return disclosure;
}
