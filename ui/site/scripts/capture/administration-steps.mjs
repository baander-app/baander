// What the scenes taken as the administrator do in the administration's pages, written against
// the labels an administrator sees. Nothing here saves, creates, deletes or starts anything: the
// pages are opened and read, and a dialog that was opened is left with its own Cancel or close
// button by the scene that opened it.
import { openFromSidebar } from './application.mjs';

/**
 * Opens the administration the way the feature pages tell a visitor to: Settings in the
 * application's sidebar, then Admin under Administration. A window that is already in the
 * administration goes back to the application first, so every scene starts with the same steps.
 */
export async function openAdministration(page) {
  const back = page.locator('aside').first().getByRole('link', { name: 'Back to app', exact: true });
  if (await back.isVisible()) await openFromSidebar(page, 'Back to app', '/music');
  await openFromSidebar(page, 'Settings', '/settings');
  await page.locator('main').getByRole('link', { name: 'Admin', exact: true }).click();
  await page.waitForURL(url => url.pathname === '/admin');
}

/** The content of one tab of an administration page, by the tab's name. */
export function tabPanel(page, name) {
  return page.getByRole('tabpanel', { name, exact: true });
}

/** Chooses a tab of the administration page that is open and returns its content. */
export async function openTab(page, name) {
  await page.getByRole('tab', { name, exact: true }).click();
  const panel = tabPanel(page, name);
  await panel.waitFor();
  return panel;
}

/** An open dialog or side panel, by its name. */
export function dialogNamed(page, name) {
  return page.getByRole('dialog', { name, exact: true });
}

/**
 * Clicks the title of a dialog, which does nothing but take the keyboard focus off the control
 * the dialog focused when it opened, so the picture shows no focus ring and no text cursor.
 */
export async function takeFocusOffControls(dialog) {
  await dialog.getByRole('heading').first().click();
}

/** Moves the pointer to the corner of the window, so that no control is drawn hovered in the picture that follows. */
export async function restPointer(page) {
  await page.mouse.move(0, 0);
}

/** Opens the server settings from the administration's sidebar and waits until their groups are listed. */
export async function openServerSettings(page) {
  await openFromSidebar(page, 'Settings', '/admin/settings');
  await tabPanel(page, 'General').getByRole('heading').first().waitFor();
}

/** One group of the server settings: its heading and the settings under it. */
export function settingsGroup(page, title) {
  return page.locator('main section').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

/** What the control of a server setting shows: "on" or "off" for a switch, the chosen option for a select. */
async function settingShown(page, label) {
  const setting = page.getByRole('group', { name: label, exact: true });
  const toggle = setting.getByRole('switch');
  if (await toggle.count() > 0) return await toggle.getAttribute('aria-checked') === 'true' ? 'on' : 'off';
  return (await setting.getByRole('combobox').innerText()).trim();
}

/**
 * Stops the run unless every setting shows the value stated for it, by the setting's label. The
 * scenes never change a setting, so a preview that differs has to be put right by its maintainer.
 */
export async function requireSettings(page, expected) {
  for (const [label, value] of Object.entries(expected)) {
    const shown = await settingShown(page, label);
    if (shown !== value) throw new Error(`The server setting "${label}" shows "${shown}"; the picture needs "${value}".`);
  }
}
