import { app, session } from 'electron';

export function hardenSecurity() {
  app.commandLine.appendSwitch('disable-site-isolation-trials');

  // Disable or restrict navigation to unknown origins
  app.on('web-contents-created', (_event, contents) => {
    contents.on('will-navigate', (e, url) => {
      const allowed = ['file:', 'http://localhost:', 'https://localhost:'];
      if (!allowed.some(a => url.startsWith(a))) e.preventDefault();
    });

    contents.setWindowOpenHandler(({ url }) => {
      const allowed = ['https://', 'http://'];
      if (allowed.some(a => url.startsWith(a))) {
        return { action: 'allow' };
      }
      return { action: 'deny' };
    });
  });

  // Example CSP and permissions tightening
  session.defaultSession.setPermissionRequestHandler((_wc, permission, cb) => {
    const allowed: Array<string> = [];
    cb(allowed.includes(permission));
  });
}
