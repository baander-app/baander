const fallback = 'UNKNOWN';

export class Env {
  static appName() {
    return window.BaanderAppConfig?.name ?? import.meta.env.VITE_APP_NAME;
  }

  static env() {
    return window.BaanderAppConfig?.environment ?? import.meta.env.VITE_APP_ENV ?? fallback;
  }

  static url() {
    return window.BaanderAppConfig?.url ?? import.meta.env.VITE_APP_URL ?? fallback;
  }

  static version() {
    return window.BaanderAppConfig.version ?? fallback;
  }

  static isProduction() {
    return Env.env() == 'production';
  }
}
