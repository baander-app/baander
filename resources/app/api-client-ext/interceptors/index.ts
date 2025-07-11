import { refreshAccessTokenInterceptor } from '@/api-client-ext/interceptors/refresh-access-token.interceptor.ts';
import { profilerParamInterceptor } from '@/api-client-ext/interceptors/profiler-param.interceptor.ts';

export function applyInterceptors() {
  refreshAccessTokenInterceptor();
  profilerParamInterceptor();
}
