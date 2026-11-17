// The pictures of the libraries page, taken as the administrator. The steps follow the page's
// guide: open the library administration, open the Add Library form, look at the library's card
// and its scan dialog, and open the library's own page. The preview already holds the sample
// library, scanned, so the scene stops short of the two buttons that would change it: it leaves
// the form without choosing Create and the scan dialog without choosing Start Scan. The last
// picture shows the server settings that would look names up at a provider, which stay off.
import { bringIntoView, openFromSidebar } from '../application.mjs';
import {
  dialogNamed, openAdministration, openServerSettings, requireSettings, restPointer, settingsGroup, tabPanel, takeFocusOffControls,
} from '../administration-steps.mjs';

// The sample library as the preview registers it: its name, and the path of its directory inside
// the containers. The directory's path on the host names a home directory, so a page that printed
// anything but this path must not be photographed.
const LIBRARY = { name: 'Music', path: '/media/music' };
const ENRICHMENT_OFF = {
  'Auto-fetch lyrics': 'off',
  'Automatic MusicBrainz matching': 'off',
  'Auto-generate recommendations': 'off',
};

const libraryCard = page => tabPanel(page, 'Libraries').locator('[data-slot="card"]').filter({ has: page.getByText(LIBRARY.name, { exact: true }) });

async function requirePath(printed) {
  const path = (await printed.innerText()).trim();
  if (path !== LIBRARY.path) throw new Error(`The page prints the library's path as "${path}", not "${LIBRARY.path}".`);
}

export default {
  name: 'libraries',
  account: 'signe@baander.app',
  steps: [
    {
      title: 'Open the library administration',
      run: async ({ page }) => {
        await openAdministration(page);
        await openFromSidebar(page, 'Library', '/admin/library');
        await libraryCard(page).waitFor();
      },
    },
    {
      title: 'Open the Add Library form',
      run: async ({ page }) => {
        await tabPanel(page, 'Libraries').getByRole('button', { name: 'Add Library', exact: true }).click();
        const form = dialogNamed(page, 'Add Library');
        for (const label of ['Name', 'Path', 'Type', 'Slug (optional)']) {
          await form.getByText(label, { exact: true }).waitFor();
        }
        await form.getByRole('button', { name: 'Create', exact: true }).waitFor();
        await takeFocusOffControls(form);
        await restPointer(page);
      },
    },
    {
      // The empty form: its fields show their own placeholders, and the Type list is closed.
      picture: 'libraries-add',
      kind: 'crop',
      overlay: 'dialog',
      region: ({ page }) => [dialogNamed(page, 'Add Library')],
    },
    {
      title: 'Leave the form without creating a library',
      run: async ({ page }) => {
        const form = dialogNamed(page, 'Add Library');
        await form.getByRole('button', { name: 'Cancel', exact: true }).click();
        await form.waitFor({ state: 'hidden' });
        await restPointer(page);
      },
    },
    {
      picture: 'libraries-scan',
      kind: 'crop',
      region: ({ page }) => [libraryCard(page)],
      before: async ({ page }) => {
        const card = libraryCard(page);
        await card.getByText('Completed', { exact: true }).waitFor();
        await card.getByText('Last scan:').waitFor();
        // The path stands under the line with the name and the status.
        await requirePath(card.getByText(LIBRARY.name, { exact: true }).locator('xpath=../following-sibling::p'));
      },
    },
    {
      title: 'Look at the scan dialog and leave it',
      run: async ({ page }) => {
        await libraryCard(page).getByRole('button', { name: 'Scan', exact: true }).click();
        const scan = dialogNamed(page, 'Scan Library');
        await scan.getByRole('button', { name: 'Start Scan', exact: true }).waitFor();
        await scan.getByRole('button', { name: 'Cancel', exact: true }).click();
        await scan.waitFor({ state: 'hidden' });
      },
    },
    {
      title: "Open the library's own page",
      run: async ({ page }) => {
        await libraryCard(page).getByRole('button', { name: 'Detail', exact: true }).click();
        await page.waitForURL(url => url.pathname.startsWith('/admin/library/'));
        await page.getByRole('heading', { name: 'Statistics', exact: true }).waitFor();
        // The counts stand in for placeholders until the page has read them.
        for (const count of ['Songs', 'Albums', 'Artists', 'Genres']) {
          await page.locator('main').getByText(count, { exact: true }).waitFor();
        }
        await restPointer(page);
      },
    },
    {
      picture: 'libraries',
      kind: 'window',
      before: async ({ page }) => {
        await page.getByRole('heading', { name: LIBRARY.name, exact: true, level: 1 }).waitFor();
        await requirePath(page.locator('main h1 + p'));
      },
    },
    {
      title: 'Open the server settings',
      run: ({ page }) => openServerSettings(page),
    },
    {
      picture: 'libraries-enrichment',
      kind: 'crop',
      region: ({ page }) => [settingsGroup(page, 'Content')],
      before: async ({ page }) => {
        await requireSettings(page, ENRICHMENT_OFF);
        await bringIntoView(page, settingsGroup(page, 'Content'));
      },
    },
  ],
};
