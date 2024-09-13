interface ApplicationConfig {
  environment: string;
  debug: boolean;
  otlEndpoint: string;
  otlServiceName: string;
}

const config: ApplicationConfig = {
  environment: import.meta.env.VITE_APP_ENV,
  debug: Boolean(import.meta.env.VITE_APP_DEBUG),
  otlEndpoint: import.meta.env.VITE_OTL_ENDPOINT,
  otlServiceName: import.meta.env.VITE_OTL_SERVICE_NAME,
};

export function useAppConfig() {
  return config;
}