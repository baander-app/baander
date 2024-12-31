export class Env {
  static appName() {
    return import.meta.env.VITE_APP_NAME;
  }

  static env() {
    return import.meta.env.VITE_APP_ENV;
  }

  static url() {
    return import.meta.env.VITE_APP_URL;
  }

  static isProduction() {
    return Env.env() == 'production';
  }
}