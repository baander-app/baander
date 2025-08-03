import { AxiosInstance } from 'axios';

export function profilerParamInterceptor(instance: AxiosInstance) {
  instance.interceptors.request.use(
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