import { app } from 'electron';

type Dict = Record<string, string>;
type Bundle = Record<string, Dict>;

const en: Dict = {
  // Top-level section labels
  'menu.app._': 'App',
  'menu.file._': 'File',
  'menu.edit._': 'Edit',
  'menu.view._': 'View',
  'menu.playback._': 'Playback',
  'menu.window._': 'Window',
  'menu.help._': 'Help',
  'menu.developer._': 'Developer',

  // App
  'menu.app.about': 'About',
  'menu.app.preferences': 'Preferences…',
  'menu.app.quit': 'Quit',

  // File
  'menu.file.open': 'Open…',
  'menu.file.close': 'Close',

  // Edit
  'menu.edit.undo': 'Undo',
  'menu.edit.redo': 'Redo',
  'menu.edit.cut': 'Cut',
  'menu.edit.copy': 'Copy',
  'menu.edit.paste': 'Paste',
  'menu.edit.pasteAndMatchStyle': 'Paste and Match Style',
  'menu.edit.delete': 'Delete',
  'menu.edit.selectAll': 'Select All',

  // View
  'menu.view.reload': 'Reload',
  'menu.view.forceReload': 'Force Reload',
  'menu.view.toggleDevTools': 'Toggle Developer Tools',
  'menu.view.toggleFullscreen': 'Toggle Full Screen',

  // Playback
  'menu.playback.toggle': 'Play/Pause',
  'menu.playback.next': 'Next',
  'menu.playback.prev': 'Previous',
  'menu.playback.seekForward': 'Seek Forward',
  'menu.playback.seekBackward': 'Seek Backward',

  // Window
  'menu.window.minimize': 'Minimize',
  'menu.window.zoom': 'Zoom',
  'menu.window.close': 'Close Window',
  'menu.window.front': 'Bring All to Front',
  'menu.window.window': 'Window',

  // Help
  'menu.help.docs': 'Documentation',
  'menu.help.report-issue': 'Report Issue',

  // Developer
  'menu.developer.openUserData': 'Open userData Folder',
  'menu.developer.openCache': 'Open Cache Folder',
  'menu.developer.openLogs': 'Open Logs Folder',
  'menu.developer.clearStore': 'Clear Storage (Cookies, Cache, Local/Session, IndexedDB)',
  'menu.developer.showConfigWindow': 'Show Config Window',
  'menu.developer.resetServerUrl': 'Reset Server URL (First-run Flow)',
};

const da: Dict = {
  // Section labels
  'menu.app._': 'App',
  'menu.file._': 'Fil',
  'menu.edit._': 'Rediger',
  'menu.view._': 'Vis',
  'menu.playback._': 'Afspilning',
  'menu.window._': 'Vindue',
  'menu.help._': 'Hjælp',
  'menu.developer._': 'Udvikler',

  // App
  'menu.app.about': 'Om',
  'menu.app.preferences': 'Indstillinger…',
  'menu.app.quit': 'Afslut',

  // File
  'menu.file.open': 'Åbn…',
  'menu.file.close': 'Luk',

  // Edit
  'menu.edit.undo': 'Fortryd',
  'menu.edit.redo': 'Gentag',
  'menu.edit.cut': 'Klip',
  'menu.edit.copy': 'Kopiér',
  'menu.edit.paste': 'Sæt ind',
  'menu.edit.pasteAndMatchStyle': 'Sæt ind og match format',
  'menu.edit.delete': 'Slet',
  'menu.edit.selectAll': 'Markér alt',

  // View
  'menu.view.reload': 'Genindlæs',
  'menu.view.forceReload': 'Gennemtving genindlæsning',
  'menu.view.toggleDevTools': 'Skift udviklerværktøjer',
  'menu.view.toggleFullscreen': 'Skift fuld skærm',

  // Playback
  'menu.playback.toggle': 'Afspil/Pause',
  'menu.playback.next': 'Næste',
  'menu.playback.prev': 'Forrige',
  'menu.playback.seekForward': 'Spol frem',
  'menu.playback.seekBackward': 'Spol tilbage',

  // Window
  'menu.window.minimize': 'Minimer',
  'menu.window.zoom': 'Zoom',
  'menu.window.close': 'Luk vindue',
  'menu.window.front': 'Bring alle frem',
  'menu.window.window': 'Vindue',

  // Help
  'menu.help.docs': 'Dokumentation',
  'menu.help.report-issue': 'Rapportér problem',

  // Developer
  'menu.developer.openUserData': 'Åbn userData-mappe',
  'menu.developer.openCache': 'Åbn cache-mappe',
  'menu.developer.openLogs': 'Åbn log-mappe',
  'menu.developer.clearStore': 'Ryd lager (Cookies, Cache, Local/Session, IndexedDB)',
  'menu.developer.showConfigWindow': 'Vis konfigurationsvindue',
  'menu.developer.resetServerUrl': 'Nulstil server-URL (første kørsel)',
};

const bundles: Bundle = {
  en,
  da,
};

function resolveLocale(): keyof typeof bundles {
  const l = (app.getLocale() || 'en').toLowerCase();
  if (l.startsWith('da')) return 'da';
  return 'en';
}

export function createMenuTranslator(locale?: string) {
  const loc: keyof typeof bundles = locale
                                    ? (locale.split('-')[0] as keyof typeof bundles) in bundles
                                      ? (locale.split('-')[0] as keyof typeof bundles)
                                      : 'en'
                                    : resolveLocale();

  const dict = bundles[loc] || bundles.en;

  const t = (key: string) => {
    if (dict[key]) return dict[key];
    // Fallback: return last path segment title-cased
    const seg = key.split('.').pop() || key;
    return seg
      .replace(/[-_]/g, ' ')
      .replace(/\b\w/g, (c) => c.toUpperCase());
  };

  return t;
}
