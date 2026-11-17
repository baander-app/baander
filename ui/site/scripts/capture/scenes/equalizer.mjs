// The pictures of the equalizer page, taken as the listener. The steps follow the page's guide:
// play a song, open the equalizer, start from a preset and save a profile. Every setting the
// pictures show is set here to a stated value, and the two device profiles are found by name
// before they are created, so a later run photographs the same state.
import {
  answerTo, bringIntoView, card, closeSidePanel, isPlaying, openFromSidebar, pausePlayback, playSong, requireStored, scrollToTop, setSlider,
} from '../application.mjs';
import { LISTENER } from '../checks.mjs';

// The track of constant tones, one on each band of the equalizer, so the analyser stands still.
const SONG = { album: 'Midnight Transit', title: 'Standing Wave' };
const PROFILES = [{ name: 'Headphones', icon: 'headphones' }, { name: 'Living room speakers', icon: 'speakers' }];
const PRESET = 'ROCK';
// The band changed between the two compared versions, and the gain it is given.
const CHANGED_BAND = { label: '1K', point: 6, gain: 3 };

const analyser = page => page.getByRole('region', { name: 'Input analyzer', exact: true });
const pressed = async button => await button.getAttribute('aria-pressed') === 'true';

// The cards of the three views that are taken twice, as a crop of the cards and as a whole window
// with the cards in view.
const compareCards = page => [card(page, 'Equalizer'), card(page, 'A/B Compare')];
const curveCards = page => [card(page, 'Equalizer')];
const dynamicsCards = page => [card(page, 'Processing')];

// One module of the Processing card: the button with its name, which opens its controls, and the
// button beside it that bypasses or enables it.
function processingModule(page, name) {
  const open = card(page, 'Processing').getByRole('button', { name, exact: true });
  return { open, bypass: open.locator('xpath=..').getByTitle(/^(Bypass|Enable)$/) };
}

async function setModuleEnabled(page, name, enabled) {
  const { bypass } = processingModule(page, name);
  if (await bypass.getAttribute('title') !== (enabled ? 'Bypass' : 'Enable')) await bypass.click();
}

export default {
  name: 'equalizer',
  account: LISTENER,
  steps: [
    {
      title: 'Play a song',
      run: ({ page }) => playSong(page, SONG),
    },
    {
      title: 'Open the equalizer',
      run: async ({ page }) => {
        // The page reads the account's device profiles as it opens; a profile is looked for only once the list shows them all.
        const profilesRead = answerTo(page, 'GET', /^\/api\/user\/eq-profiles\/$/);
        await openFromSidebar(page, 'Equalizer', '/equalizer');
        const stored = (await (await profilesRead).json()).data.length;
        if (stored > 0) await card(page, 'Device Profiles').getByRole('button', { name: 'Save', exact: true }).nth(stored - 1).waitFor();
        // The equalizer's cards stand side by side only when the panel beside the page is closed.
        await closeSidePanel(page);
        const spectrum = analyser(page).getByRole('button', { name: 'Spectrum', exact: true });
        if (!await pressed(spectrum)) await spectrum.click();
      },
    },
    {
      title: 'Name a profile for each device',
      run: async ({ page }) => {
        const profiles = card(page, 'Device Profiles');
        for (const { name, icon } of PROFILES) {
          if (await profiles.getByText(name, { exact: true }).count() > 0) continue;
          await profiles.getByRole('button', { name: '+ New', exact: true }).click();
          await profiles.getByPlaceholder('Profile name').fill(name);
          await profiles.getByTitle(icon, { exact: true }).click();
          // When the server refuses a profile, the application keeps it in this window only, where no later run would find it.
          const created = answerTo(page, 'POST', /^\/api\/user\/eq-profiles\/$/);
          await profiles.getByRole('button', { name: 'Create', exact: true }).click();
          await requireStored(created, `the profile ${name}`);
          await profiles.getByText(name, { exact: true }).waitFor();
        }
      },
    },
    {
      title: 'Set the processing',
      run: async ({ page }) => {
        const processing = card(page, 'Processing');
        // The compressor and the master gain show their controls; the card with every module open is taller than the window.
        if (await processing.getByRole('slider', { name: 'Thresh', exact: true }).count() === 0) {
          await processingModule(page, 'Compressor').open.click();
        }
        const normalize = processing.getByRole('button', { name: 'Normalize', exact: true });
        if (await normalize.count() === 0) await processingModule(page, 'Master Gain').open.click();
        await setModuleEnabled(page, 'Compressor', true);
        for (const name of ['Stereo Width', 'Crossfeed', 'Loudness']) {
          await setModuleEnabled(page, name, false);
        }
        // The loudness target can be chosen only while normalization is on.
        if (await processing.getByRole('combobox').isDisabled()) await normalize.click();
        await setSlider(processing.getByRole('slider', { name: 'Gain', exact: true }), 0, processing.getByText('Processing', { exact: true }));
      },
    },
    {
      title: 'Start from a preset',
      run: ({ page }) => card(page, 'Presets').getByRole('button', { name: PRESET, exact: true }).click(),
    },
    {
      // The top of the page: the analyser, the presets and the device profiles. The ten bands lie
      // below what a window of this size shows; equalizer-compare has them.
      picture: 'equalizer-window',
      kind: 'window',
      before: async ({ page }) => {
        await scrollToTop(page);
        // The analyser draws only while a track plays.
        if (!await isPlaying(page, SONG.title)) throw new Error(`${SONG.title} is not playing.`);
        const spectrum = analyser(page).getByRole('img', { name: 'Input spectrum with configured EQ response', exact: true });
        // The track has ten tones, so a drawn spectrum has at least ten bars.
        await page.waitForFunction(drawn => [...drawn.children].filter(bar => parseFloat(bar.style.height) > 0).length >= 10, await spectrum.elementHandle());
      },
    },
    {
      title: 'Compare two versions',
      run: async ({ page }) => {
        await pausePlayback(page);
        const compare = card(page, 'A/B Compare');
        await compare.getByRole('button', { name: 'Slot A', exact: true }).click();
        const band = page.getByRole('slider', { name: `${CHANGED_BAND.label}Hz equalizer band`, exact: true });
        await setSlider(band, CHANGED_BAND.gain, card(page, 'Equalizer').getByText('Equalizer', { exact: true }));
        await compare.getByRole('button', { name: 'Slot B', exact: true }).click();
      },
    },
    {
      title: 'Save a profile',
      run: async ({ page }) => {
        for (const { name } of PROFILES) {
          const saved = answerTo(page, 'PUT', /^\/api\/user\/eq-profiles\/[^/]+$/);
          await card(page, 'Device Profiles').getByText(name, { exact: true }).locator('xpath=..').getByRole('button', { name: 'Save', exact: true }).click();
          await requireStored(saved, `the settings of the profile ${name}`);
        }
      },
    },
    {
      picture: 'equalizer-compare',
      kind: 'crop',
      region: ({ page }) => compareCards(page),
      before: ({ page }) => bringIntoView(page, ...compareCards(page)),
    },
    {
      // The same view as a whole window, for the homepage, whose frame has a window's proportions.
      picture: 'equalizer-window-compare',
      kind: 'window',
      before: ({ page }) => bringIntoView(page, ...compareCards(page)),
    },
    {
      picture: 'equalizer-device-profiles',
      kind: 'crop',
      region: ({ page }) => [card(page, 'Device Profiles')],
      before: ({ page }) => bringIntoView(page, card(page, 'Device Profiles')),
    },
    {
      title: 'Open the parametric view',
      run: async ({ page }) => {
        const equalizer = card(page, 'Equalizer');
        await equalizer.getByRole('button', { name: 'Parametric', exact: true }).click();
        // Pressing a point selects its band, which shows the band's frequency, gain and Q.
        await equalizer.getByRole('slider', { name: new RegExp(`^Band ${CHANGED_BAND.point}:`) }).click();
      },
    },
    {
      picture: 'equalizer-curve',
      kind: 'crop',
      region: ({ page }) => curveCards(page),
      before: ({ page }) => bringIntoView(page, ...curveCards(page)),
    },
    {
      picture: 'equalizer-window-curve',
      kind: 'window',
      before: ({ page }) => bringIntoView(page, ...curveCards(page)),
    },
    {
      picture: 'equalizer-dynamics',
      kind: 'crop',
      region: ({ page }) => dynamicsCards(page),
      before: ({ page }) => bringIntoView(page, ...dynamicsCards(page)),
    },
    {
      picture: 'equalizer-window-dynamics',
      kind: 'window',
      before: ({ page }) => bringIntoView(page, ...dynamicsCards(page)),
    },
  ],
};
