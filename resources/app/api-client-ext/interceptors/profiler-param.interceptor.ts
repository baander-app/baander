import { OpenAPI } from '@/api-client/requests';

export function profilerParamInterceptor() {
  OpenAPI.interceptors.request.use(
    async (request) => {
      const searchParams = new URLSearchParams(location.search);
      const trigger = searchParams.get('XDEBUG_TRIGGER');

      if (trigger) {
        if (!request.params) {
          request.params = {};
        }


        request.params['XDEBUG_TRIGGER'] = trigger;
      }

      return request;
    },
  );
}