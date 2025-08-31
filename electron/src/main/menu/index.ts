import { Menu, BrowserWindow, app, ipcMain, shell, session, dialog } from 'electron';
import { buildMenu } from './build-menu';
import type { AppMenuContext } from './types';
import { MenuActionId } from './ids';
import { createConfigWindow } from '../windows/config-window';
import { getServerUrl, setServerUrl } from '../../shared/config-store';

let currentCtx: AppMenuContext | null = null;

export function setupApplicationMenu(ctx: AppMenuContext) {
  currentCtx = ctx;
  const menu = buildMenu(ctx);
  Menu.setApplicationMenu(menu);

  // Wire menu item actions by id
  wireMenuActions(menu);

  // Basic IPC wiring example for menu actions
  ipcMain.on('menu:action', (_e, actionId: string) => {
    handleMenuAction(actionId);
  });
}

function sendToFocused(channel: string, payload?: any) {
  const win = BrowserWindow.getFocusedWindow();
  if (win && !win.isDestroyed()) {
    win.webContents.send(channel, payload);
  }
}

function wireMenuActions(menu: Menu) {
  const byId = (id: string) => menu.getMenuItemById(id);

  // App
  const appAbout = byId(MenuActionId.AppAbout);
  if (appAbout) appAbout.click = () => {
    if ((app as any).showAboutPanel) app.showAboutPanel();
  };

  const appPrefs = byId(MenuActionId.AppPreferences);
  if (appPrefs) appPrefs.click = () => {
    sendToFocused('menu:open-preferences');
  };

  const appQuit = byId(MenuActionId.AppQuit);
  if (appQuit) appQuit.click = () => app.quit();

  // File
  const fileOpen = byId(MenuActionId.FileOpen);
  if (fileOpen) fileOpen.click = async () => {
    const win = BrowserWindow.getFocusedWindow();
    if (!win) return;
    const res = await dialog.showOpenDialog(win, {
      properties: ['openFile', 'multiSelections'],
    });
    if (!res.canceled) {
      sendToFocused('menu:file-open', res.filePaths);
    }
  };

  const fileClose = byId(MenuActionId.FileClose);
  if (fileClose) fileClose.click = () => {
    BrowserWindow.getFocusedWindow()?.close();
  };

  // Edit roles are usually handled by Electron roles in section definitions

  // View
  const viewReload = byId(MenuActionId.ViewReload);
  if (viewReload) viewReload.click = () => BrowserWindow.getFocusedWindow()?.reload();

  const viewToggleDevTools = byId(MenuActionId.ViewToggleDevTools);
  if (viewToggleDevTools) viewToggleDevTools.click = () => {
    const win = BrowserWindow.getFocusedWindow();
    if (win) win.webContents.toggleDevTools();
  };

  const viewToggleFullscreen = byId(MenuActionId.ViewToggleFullScreen);
  if (viewToggleFullscreen) viewToggleFullscreen.click = () => {
    const win = BrowserWindow.getFocusedWindow();
    if (win) win.setFullScreen(!win.isFullScreen());
  };


  // Playback (forward to renderer)
  const forwardPlayback = (id: string) => () => handleMenuAction(id);
  const pbToggle = byId(MenuActionId.PlaybackToggle);
  if (pbToggle) pbToggle.click = forwardPlayback(MenuActionId.PlaybackToggle);

  const pbNext = byId(MenuActionId.PlaybackNext);
  if (pbNext) pbNext.click = forwardPlayback(MenuActionId.PlaybackNext);

  const pbPrev = byId(MenuActionId.PlaybackPrev);
  if (pbPrev) pbPrev.click = forwardPlayback(MenuActionId.PlaybackPrev);

  const pbSeekFwd = byId(MenuActionId.PlaybackSeekForward);
  if (pbSeekFwd) pbSeekFwd.click = forwardPlayback(MenuActionId.PlaybackSeekForward);

  const pbSeekBack = byId(MenuActionId.PlaybackSeekBackward);
  if (pbSeekBack) pbSeekBack.click = forwardPlayback(MenuActionId.PlaybackSeekBackward);

  // Window
  const winMin = byId(MenuActionId.WindowMinimize);
  if (winMin) winMin.click = () => BrowserWindow.getFocusedWindow()?.minimize();

  const winClose = byId(MenuActionId.WindowClose);
  if (winClose) winClose.click = () => BrowserWindow.getFocusedWindow()?.close();


  // Help
  const docsUrl = process.env.BAANDER_DOCS_URL || 'https://baander.app/docs';
  const issuesUrl = process.env.BAANDER_ISSUES_URL || 'https://baander.app/support';
  byId(MenuActionId.HelpDocs)!.click = () => shell.openExternal(docsUrl);
  byId(MenuActionId.HelpReportIssue)!.click = () => shell.openExternal(issuesUrl);

  // Developer tools
  const open = async (p: string) => { await shell.openPath(app.getPath(p as any)); };

  byId(MenuActionId.DevOpenUserData)!.click = () => open('userData');
  const openCache = byId(MenuActionId.DevOpenCache);
  if (openCache) openCache.click = () => open('cache');

  const openLogs = byId(MenuActionId.DevOpenLogs);
  if (openLogs) openLogs.click = () => open('logs');

  const clearStore = byId(MenuActionId.DevClearStore);
  if (clearStore) clearStore.click = async () => {
    const ses = session.defaultSession;
    if (ses) {
      await ses.clearCache();
      await ses.clearStorageData({
        storages: ['cookies', 'localstorage', 'serviceworkers', 'websql', 'indexdb'],
      });
    }
    BrowserWindow.getFocusedWindow()?.reload();
  };

  const showConfig = byId(MenuActionId.DevShowConfigWindow);
  if (showConfig) showConfig.click = () => {
    createConfigWindow();
  };

  const resetServer = byId(MenuActionId.DevResetServerUrl);
  if (resetServer) resetServer.click = () => {
    if (getServerUrl()) {
      setServerUrl('');
    }
    app.relaunch();
    app.exit(0);
  };
}

function handleMenuAction(actionId: string) {
  switch (actionId) {
    // App
    case MenuActionId.AppAbout:
      if ((app as any).showAboutPanel) app.showAboutPanel();
      return;
    case MenuActionId.AppPreferences:
      return sendToFocused('menu:open-preferences');
    case MenuActionId.AppQuit:
      return app.quit();

    // File
    case MenuActionId.FileOpen:
      // Handled in wireMenuActions to return selected paths to renderer
      return;
    case MenuActionId.FileClose:
      return BrowserWindow.getFocusedWindow()?.close();

    // Edit is handled by roles

    // View
    case MenuActionId.ViewReload:
      return BrowserWindow.getFocusedWindow()?.reload();
    case MenuActionId.ViewToggleDevTools: {
      const w = BrowserWindow.getAllWindows().find(w => !w.isDestroyed());
      console.log('w', w);
      if (w) w.webContents.toggleDevTools();
      return;
    }
    case MenuActionId.ViewToggleFullScreen: {
      const w = BrowserWindow.getFocusedWindow();
      if (w) w.setFullScreen(!w.isFullScreen());
      return;
    }

    // Playback (forward to renderer)
    case MenuActionId.PlaybackToggle:
    case MenuActionId.PlaybackNext:
    case MenuActionId.PlaybackPrev:
    case MenuActionId.PlaybackSeekForward:
    case MenuActionId.PlaybackSeekBackward:
      return sendToFocused('menu:action', actionId);

    // Window
    case MenuActionId.WindowMinimize:
      return BrowserWindow.getFocusedWindow()?.minimize();
    case MenuActionId.WindowClose:
      return BrowserWindow.getFocusedWindow()?.close();

    // Help
    case MenuActionId.HelpDocs:
      return shell.openExternal(process.env.BAANDER_DOCS_URL || 'https://baander.app/docs');
    case MenuActionId.HelpReportIssue:
      return shell.openExternal(process.env.BAANDER_ISSUES_URL || 'https://baander.app/support');

    // Developer
    case MenuActionId.DevOpenUserData:
      return shell.openPath(app.getPath('userData'));
    case MenuActionId.DevOpenCache:
      return shell.openPath(app.getPath('temp'));
    case MenuActionId.DevOpenLogs:
      return shell.openPath(app.getPath('logs'));
    case MenuActionId.DevClearStore:
      return (async () => {
        const ses = session.defaultSession;
        if (ses) {
          await ses.clearCache();
          await ses.clearStorageData({
            storages: ['cookies', 'localstorage', 'serviceworkers', 'websql', 'indexdb'],
          });
        }
        BrowserWindow.getFocusedWindow()?.reload();
      })();
    case MenuActionId.DevShowConfigWindow:
      return createConfigWindow();
    case MenuActionId.DevResetServerUrl:
      if (getServerUrl()) setServerUrl('');
      app.relaunch();
      app.exit(0);
      return;

    default:
      return;
  }
}

// Optional: helper to rebuild the menu when state (e.g., isPlaying) changes
export function refreshApplicationMenu(nextState: Partial<AppMenuContext['state']>) {
  if (!currentCtx) return;
  currentCtx = { ...currentCtx, state: { ...currentCtx.state, ...nextState } };
  const menu = buildMenu(currentCtx);
  Menu.setApplicationMenu(menu);
  wireMenuActions(menu);
}
