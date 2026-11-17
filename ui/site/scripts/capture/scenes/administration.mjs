// The pictures of the administration page, taken as the administrator. The steps follow the
// page's guide: open the administration, find a job on the Jobs tab and open its details, read
// the Scheduler tab, and open Library access for a listener under Security. The scene changes
// nothing: it leaves the Library access dialog with Cancel, and starts, retries, pauses and
// deletes no job.
import { WINDOW, awaitedLater, openFromSidebar, restPointer } from '../application.mjs';
import { dialogNamed, openAdministration, openTab, tabPanel, takeFocusOffControls } from '../administration-steps.mjs';
import { ADMINISTRATOR, LISTENER } from '../checks.mjs';

// The job whose details are shown: the scan of the sample library.
const JOB = 'ScanLibraryCommand';

// The table of scheduled jobs: the line of column headings and every row under it.
const scheduledJobs = page => tabPanel(page, 'Scheduler').getByText('Expression', { exact: true }).locator('xpath=../..');

// What the Jobs tab showed when the first variant of its picture was taken.
let jobsShown;

export default {
  name: 'administration',
  account: ADMINISTRATOR,
  steps: [
    {
      title: 'Open the administration',
      run: ({ page }) => openAdministration(page),
    },
    {
      title: 'Open the Jobs tab',
      run: async ({ page }) => {
        const jobs = await openTab(page, 'Jobs');
        await jobs.getByText('Success rate', { exact: true }).waitFor();
        await jobs.getByText('finished', { exact: true }).first().waitFor();
        await jobs.getByRole('heading', { name: 'Transport Health', exact: true }).waitFor();
        await restPointer(page);
      },
    },
    {
      picture: 'administration',
      kind: 'window',
      before: async ({ page }) => {
        const jobs = tabPanel(page, 'Jobs');
        // The Transport Health card prints the name of the worker that reads the queue, which is
        // the host name of its container. It lies under the list of jobs and must stay under the window.
        const transport = await jobs.getByRole('heading', { name: 'Transport Health', exact: true }).boundingBox();
        if (transport.y < WINDOW.height) {
          throw new Error('The list of jobs is too short to keep the Transport Health card out of the window.');
        }
        // Each job is listed with how long ago it was created, and a minute can turn between the two variants.
        const shown = await jobs.innerText();
        jobsShown ??= shown;
        if (shown !== jobsShown) throw new Error('The Jobs tab changed between the two variants of its picture; run the scene again.');
      },
    },
    {
      title: 'Find a job and open its details',
      run: async ({ page }) => {
        const jobs = tabPanel(page, 'Jobs');
        const listed = awaitedLater(page.waitForResponse((response) => {
          const url = new URL(response.url());
          return url.pathname === '/api/monitor/jobs' && url.searchParams.get('name') === JOB;
        }));
        await jobs.getByPlaceholder('Filter by name...').fill(JOB);
        await listed;
        await jobs.getByTitle(JOB, { exact: true }).first().click();
        const details = dialogNamed(page, 'Job details');
        await details.getByRole('heading', { name: JOB, exact: true }).waitFor();
        await details.getByText('finished', { exact: true }).first().waitFor();
        await restPointer(page);
      },
    },
    {
      picture: 'administration-job',
      kind: 'crop',
      overlay: 'dialog',
      region: ({ page }) => [dialogNamed(page, 'Job details')],
    },
    {
      title: 'Close the details',
      run: async ({ page }) => {
        const details = dialogNamed(page, 'Job details');
        await details.getByRole('button', { name: 'Close panel', exact: true }).click();
        await details.waitFor({ state: 'hidden' });
        await tabPanel(page, 'Jobs').getByRole('button', { name: 'Clear', exact: true }).click();
      },
    },
    {
      title: 'Open the Scheduler tab',
      run: async ({ page }) => {
        await openTab(page, 'Scheduler');
        await scheduledJobs(page).waitFor();
        await restPointer(page);
      },
    },
    {
      picture: 'administration-scheduler',
      kind: 'crop',
      region: ({ page }) => [scheduledJobs(page)],
    },
    {
      title: 'Open Library access for a listener',
      run: async ({ page }) => {
        await openFromSidebar(page, 'Security', '/admin/security');
        await tabPanel(page, 'Users').getByRole('button', { name: `Actions for ${LISTENER}`, exact: true }).click();
        await page.getByRole('menuitem', { name: 'Library access', exact: true }).click();
        const access = dialogNamed(page, 'Library access');
        await access.getByRole('checkbox').first().waitFor();
        await access.getByRole('button', { name: 'Save', exact: true }).waitFor();
        await takeFocusOffControls(access);
        await restPointer(page);
      },
    },
    {
      picture: 'administration-access',
      kind: 'crop',
      overlay: 'dialog',
      region: ({ page }) => [dialogNamed(page, 'Library access')],
    },
    {
      title: 'Leave her access as it is',
      run: async ({ page }) => {
        const access = dialogNamed(page, 'Library access');
        await access.getByRole('button', { name: 'Cancel', exact: true }).click();
        await access.waitFor({ state: 'hidden' });
      },
    },
  ],
};
