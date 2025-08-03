import { profilerParamInterceptor } from '@/libs/api-client/interceptors/profiler-param.interceptor.ts';
import { AxiosInstance } from 'axios';
import { authInterceptor } from '@/libs/api-client/interceptors/auth.interceptor.ts';

export function applyInterceptors(axiosInstance: AxiosInstance) {
  authInterceptor(axiosInstance);
  profilerParamInterceptor(axiosInstance);
}
