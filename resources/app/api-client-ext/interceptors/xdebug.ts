import { InternalAxiosRequestConfig } from 'axios';

export async function xdebug(request: InternalAxiosRequestConfig<any>) {
  const searchParams = new URLSearchParams(location.search);
  const trigger = searchParams.get('XDEBUG_TRIGGER');

  if (trigger) {
    if (!request.params) {
      request.params = {};
    }


    request.params['XDEBUG_TRIGGER'] = trigger;
  }

  return request;
}