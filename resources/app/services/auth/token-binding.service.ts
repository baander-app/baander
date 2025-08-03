export class TokenBindingService {
  private sessionId: string | null = null;
  private fingerprint: string | null = null;

  async generateFingerprint(): Promise<string> {
    if (this.fingerprint) {
      return this.fingerprint;
    }

    const components = [
      // Screen properties
      `screen:${screen.width}x${screen.height}x${screen.colorDepth}`,

      // Timezone
      `tz:${Intl.DateTimeFormat().resolvedOptions().timeZone}`,

      // Language preferences
      `lang:${navigator.language}`,
      `langs:${navigator.languages?.join(',')}`,

      // User agent (truncated to avoid excessive length)
      `ua:${navigator.userAgent.slice(0, 100)}`,

      // Canvas fingerprint
      await this.getCanvasFingerprint(),

      // WebGL fingerprint
      await this.getWebGLFingerprint(),

      // Available fonts
      await this.getFontFingerprint(),
    ];

    // Create a hash of all components
    this.fingerprint = await this.hashString(components.join('|'));
    return this.fingerprint;
  }

  private async getCanvasFingerprint(): Promise<string> {
    try {
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      if (!ctx) return 'no-canvas';

      canvas.width = 200;
      canvas.height = 50;

      ctx.textBaseline = 'top';
      ctx.font = '14px Arial';
      ctx.fillStyle = '#f60';
      ctx.fillRect(125, 1, 62, 20);
      ctx.fillStyle = '#069';
      ctx.fillText('Token binding 🔒', 2, 15);
      ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
      ctx.fillText('Security layer', 4, 35);

      return canvas.toDataURL();
    } catch {
      return 'canvas-error';
    }
  }

  private async getWebGLFingerprint(): Promise<string> {
    try {
      const canvas = document.createElement('canvas');
      const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
      if (!gl) return 'no-webgl';

      const debugInfo = 'getExtension' in gl ? gl.getExtension('WEBGL_debug_renderer_info') : 'no-debug-info';
      if (!debugInfo) return 'no-debug-info';

      const vendor = 'getParameter' in gl ? gl.getParameter(debugInfo['UNMASKED_VENDOR_WEBGL']) : 'no-vendor';
      const renderer = 'getParameter' in gl ? gl.getParameter(debugInfo['UNMASKED_RENDERER_WEBGL']) : 'no-renderer';

      return `webgl:${vendor}|${renderer}`;
    } catch {
      return 'webgl-error';
    }
  }

  private async getFontFingerprint(): Promise<string> {
    try {
      const testFonts = ['Arial', 'Times New Roman', 'Helvetica', 'Georgia', 'Verdana'];
      const availableFonts = [];

      for (const font of testFonts) {
        if (this.isFontAvailable(font)) {
          availableFonts.push(font);
        }
      }

      return `fonts:${availableFonts.join(',')}`;
    } catch {
      return 'fonts-error';
    }
  }

  private isFontAvailable(font: string): boolean {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    if (!ctx) return false;

    const text = 'mmmmmmmmlli';
    ctx.font = `12px monospace`;
    const baselineWidth = ctx.measureText(text).width;

    ctx.font = `12px ${font}, monospace`;
    const testWidth = ctx.measureText(text).width;

    return baselineWidth !== testWidth;
  }

  private async hashString(str: string): Promise<string> {
    const encoder = new TextEncoder();
    const data = encoder.encode(str);
    const hashBuffer = await crypto.subtle.digest('SHA-256', data);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
  }

  setSessionId(sessionId: string): void {
    this.sessionId = sessionId;
    localStorage.setItem('session_id', sessionId);
  }

  getSessionId(): string | null {
    if (!this.sessionId) {
      this.sessionId = localStorage.getItem('session_id');
    }
    return this.sessionId;
  }

  clearSessionId(): void {
    this.sessionId = null;
    localStorage.removeItem('session_id');
  }

  clear(): void {
    this.fingerprint = null;
    this.clearSessionId();
    localStorage.removeItem('client_fingerprint');
  }

  validateEnvironment(): boolean {
    const storedFingerprint = localStorage.getItem('client_fingerprint');

    if (storedFingerprint && this.fingerprint && storedFingerprint !== this.fingerprint) {
      console.warn('Client environment changed, clearing tokens');
      return false;
    }

    if (!storedFingerprint && this.fingerprint) {
      localStorage.setItem('client_fingerprint', this.fingerprint);
    }

    return true;
  }
}

// Singleton instance
export const tokenBindingService = new TokenBindingService();
