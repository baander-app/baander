const fallback = 'UNKNOWN';

export class Env {
  static appName() {
    return window.BaanderAppInfo?.name ?? import.meta.env.VITE_APP_NAME;
  }

  static env() {
    return window.BaanderAppInfo?.environment ?? import.meta.env.VITE_APP_ENV ?? fallback;
  }

  static url() {
    return window.BaanderAppInfo?.url ?? import.meta.env.VITE_APP_URL ?? fallback;
  }

  static version() {
    return window.BaanderAppInfo?.version ?? fallback;
  }

  static isProduction() {
    return Env.env() == 'production';
  }
}