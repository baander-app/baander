import Axios, { AxiosError, AxiosRequestConfig, InternalAxiosRequestConfig } from 'axios';
import { applyInterceptors } from '@/app/libs/api-client/interceptors';

export interface CustomAxiosRequestConfig extends AxiosRequestConfig {
  _skipAuth?: boolean;
  _didRetry?: boolean;
}

const headers: Record<string, string> = {
  'accept': 'application/json',
  'X-Requested-With': 'XMLHttpRequest',
}

const csrfToken = document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

const AXIOS_INSTANCE = Axios.create({
  baseURL: import.meta.env.VITE_APP_URL,
  withCredentials: true,
});

AXIOS_INSTANCE.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    if (csrfToken) {
      config.headers['X-CSRF-TOKEN'] = csrfToken;
    }

    return config;
  }
)

export {
  AXIOS_INSTANCE,
}

applyInterceptors(AXIOS_INSTANCE);

// add a second `options` argument here if you want to pass extra options to each generated query
export const customInstance = <T>(
  config: AxiosRequestConfig,
  options?: CustomAxiosRequestConfig,
): Promise<T> => {
  const source = Axios.CancelToken.source();
  const promise = AXIOS_INSTANCE({
    ...config,
    ...options,
    cancelToken: source.token,
  }).then(({ data }) => data);

  // @ts-ignore
  promise.cancel = () => {
    source.cancel('Query was cancelled');
  };

  return promise;
};

// In some case with react-query and swr you want to be able to override the return error type so you can also do it here like this
export type ErrorType<Error> = AxiosError<Error>;

export type BodyType<BodyData> = BodyData;
