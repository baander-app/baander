import Axios, { AxiosError, AxiosRequestConfig } from 'axios';
import { applyInterceptors } from '@/libs/api-client/interceptors';

export interface CustomAxiosRequestConfig extends AxiosRequestConfig {
  _skipAuth?: boolean;
  _didRetry?: boolean;
}

export const AXIOS_INSTANCE = Axios.create({
  baseURL: import.meta.env.VITE_APP_URL,
  headers: {
    'accept': 'application/json',
    'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')!.getAttribute('content') as string,
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
}); // use your own URL here or environment variable

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