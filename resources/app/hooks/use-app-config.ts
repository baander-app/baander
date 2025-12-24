interface ApplicationConfig {
  environment: string;
  debug: boolean;
}

const config: ApplicationConfig = {
  environment: import.meta.env.VITE_APP_ENV,
  debug: Boolean(import.meta.env.VITE_APP_DEBUG),
};

export function useAppConfig() {
  return config;
}