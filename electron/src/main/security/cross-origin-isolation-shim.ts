import { BrowserWindow } from 'electron';

export function enableCrossOriginIsolation(win: BrowserWindow) {
  const { session } = win.webContents;
  session.webRequest.onHeadersReceived((details, callback) => {
    const headers = details.responseHeaders || {};
    headers['Cross-Origin-Opener-Policy'] = ['same-origin'];
    headers['Cross-Origin-Embedder-Policy'] = ['require-corp'];
    callback({ responseHeaders: headers });
  });
}

