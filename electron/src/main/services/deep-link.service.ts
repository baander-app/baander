import { app } from 'electron';
import { createMainWindow } from '../windows/main-window';
import { createConfigWindow } from '../windows/config-window';
import { enableCrossOriginIsolation } from '../security/cross-origin-isolation-shim';
import { mainLog } from '../log';

export class DeepLinkService {
  private pendingUrl: string | null = null;
  private getServerUrlSync: () => string | null;

  constructor(getServerUrlSync: () => string | null) {
    this.getServerUrlSync = getServerUrlSync;
  }

  /**
   * Initialize deep link protocol handling
   */
  initializeProtocol() {
    // Set as default protocol client for your app's scheme
    if (process.defaultApp) {
      if (process.argv.length >= 2) {
        app.setAsDefaultProtocolClient('baander', process.execPath, [process.argv[1]]);
      }
    } else {
      app.setAsDefaultProtocolClient('baander');
    }

    // Check if the app was opened with a deep link URL
    if (process.argv.length > 1) {
      const url = this.findUrlInCommandLine(process.argv);
      if (url) {
        mainLog.log('App started with deep link:', url);
        this.pendingUrl = url;
      }
    }
  }

  /**
   * Setup event listeners for deep link handling
   */
  setupEventListeners() {
    // Handle the protocol on Windows/Linux
    app.on('second-instance', (event, commandLine, workingDirectory) => {
      const url = this.findUrlInCommandLine(commandLine);
      if (url) {
        this.handleDeepLink(url);
      }

      // Focus/restore existing window
      this.focusOrCreateWindow();
    });

    // Handle the protocol on macOS
    app.on('open-url', (event, url) => {
      event.preventDefault();
      this.handleDeepLink(url);
    });
  }

  /**
   * Get and consume the pending deep link URL
   */
  getPendingUrl(): string | null {
    const url = this.pendingUrl;
    this.pendingUrl = null;
    return url;
  }

  /**
   * Clear the pending deep link URL
   */
  clearPendingUrl(): void {
    this.pendingUrl = null;
  }

  /**
   * Handle a deep link URL
   */
  private handleDeepLink(url: string) {
    mainLog.log('Deep link received:', url);

    // Store the URL for the renderer to consume
    this.pendingUrl = url;

    // Ensure we have a window to handle the deep link
    const existingWindow = this.getExistingWindow();

    if (existingWindow) {
      // Window exists, focus it and notify renderer
      this.focusWindow(existingWindow);
      existingWindow.webContents.send('baander:deeplink:received', url);
    } else {
      // No window exists, create one
      const newWindow = this.createAppropriateWindow();

      // Wait for the window to be ready before sending the deep link
      newWindow.webContents.once('did-finish-load', () => {
        newWindow.webContents.send('baander:deeplink:received', url);
      });

      newWindow.show();
    }
  }

  /**
   * Focus or create a window
   */
  private focusOrCreateWindow() {
    const existingWindow = this.getExistingWindow();

    if (existingWindow) {
      this.focusWindow(existingWindow);
    } else {
      const newWindow = this.createAppropriateWindow();
      newWindow.show();
    }
  }

  /**
   * Get existing window based on current configuration
   */
  private getExistingWindow() {
    const configured = this.getServerUrlSync();
    return configured
           ? (createMainWindow as any).get?.()
           : (createConfigWindow as any)?.get?.() || null;
  }

  /**
   * Create appropriate window based on configuration
   */
  private createAppropriateWindow() {
    const configured = this.getServerUrlSync();
    const window = configured ? createMainWindow() : createConfigWindow();
    enableCrossOriginIsolation(window);
    return window;
  }

  /**
   * Focus and restore a window
   */
  private focusWindow(window: Electron.BrowserWindow) {
    if (window.isMinimized()) window.restore();
    if (!window.isVisible()) window.show();
    window.focus();
  }

  /**
   * Find baander:// URLs in command line arguments
   */
  private findUrlInCommandLine(commandLine: string[]): string | null {
    for (const arg of commandLine) {
      if (arg.startsWith('baander://')) {
        return arg;
      }
    }
    return null;
  }
}
