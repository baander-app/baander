import { session, OnBeforeSendHeadersListenerDetails, OnHeadersReceivedListenerDetails } from 'electron';
import { URL } from 'node:url';

// Cache preflight request info keyed by request id
const preflightCache = new Map<number, { reqHeaders?: string; reqMethod?: string }>();

let removeHandlers: (() => void) | null = null;

export function installOrUpdateCorsShim(userServerURL: string | undefined, rendererOrigin: string) {
  const ses = session.defaultSession;
  if (!ses) return;

  // Clear previous listeners if any
  if (removeHandlers) {
    removeHandlers();
    removeHandlers = null;
  }

  if (!userServerURL) return;

  const server = new URL(userServerURL);
  const serverOrigin = `${server.protocol}//${server.host}`;

  // Normalize origins (strip trailing slash), file:// pages send Origin: null
  const normalizeOrigin = (o: string) => o.replace(/\/$/, '');
  const configuredAllowedOrigin =
    rendererOrigin === 'file://' ? 'null' : normalizeOrigin(rendererOrigin);

  const urlsFilter = { urls: [`${serverOrigin}/*`] };

  const beforeSend = (details: OnBeforeSendHeadersListenerDetails, callback: (response: any) => void) => {
    const headers = { ...details.requestHeaders };

    headers['X-Baander-Client'] = 'Baander Desktop (0.0.0)';

    // Ensure Origin header is present; if missing, set our configured one
    if (!headers['Origin'] && !headers['origin']) {
      headers['Origin'] = configuredAllowedOrigin;
    } else if (headers['Origin']) {
      headers['Origin'] = headers['Origin'] === 'null' ? 'null' : normalizeOrigin(String(headers['Origin']));
    } else if (headers['origin']) {
      headers['origin'] = headers['origin'] === 'null' ? 'null' : normalizeOrigin(String(headers['origin']));
    }

    // Store requested headers/method for preflight OPTIONS (capture from request headers here)
    if (details.method === 'OPTIONS') {
      const reqId = (details as any).id as number | undefined;
      const reqHeaders =
        (headers['Access-Control-Request-Headers'] as string | undefined) ||
        (headers['access-control-request-headers'] as string | undefined);
      const reqMethod =
        (headers['Access-Control-Request-Method'] as string | undefined) ||
        (headers['access-control-request-method'] as string | undefined);
      if (reqId != null) {
        preflightCache.set(reqId, { reqHeaders, reqMethod });
      }
    }

    callback({ requestHeaders: headers });
  };

  const onHeaders = (details: OnHeadersReceivedListenerDetails, callback: (response: any) => void) => {
    const headers = details.responseHeaders ?? {};

    const set = (name: string, value: string) => {
      for (const key of Object.keys(headers)) {
        if (key.toLowerCase() === name.toLowerCase()) delete headers[key];
      }
      headers[name] = [value];
    };

    // Echo back the exact Origin from the request if present; otherwise fall back to configured
    const reqOriginHeader =
      (details as any).headers?.Origin ??
      (details as any).headers?.origin ??
      configuredAllowedOrigin;
    const effectiveOrigin =
      reqOriginHeader === 'null' ? 'null' : normalizeOrigin(String(reqOriginHeader));

    set('Access-Control-Allow-Origin', effectiveOrigin);
    set('Access-Control-Allow-Credentials', 'true');
    // Optional but recommended for caches/proxies
    set('Vary', 'Origin');

    if (details.method === 'OPTIONS') {
      const reqId = (details as any).id as number | undefined;
      const cached = reqId != null ? preflightCache.get(reqId) : undefined;

      const allowMethods = cached?.reqMethod || 'GET,POST,PUT,PATCH,DELETE,OPTIONS';
      const allowHeaders =
        cached?.reqHeaders ||
        'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, Accept, Origin';

      set('Access-Control-Allow-Methods', allowMethods);
      set('Access-Control-Allow-Headers', allowHeaders);
      set('Access-Control-Max-Age', '600');

      if (reqId != null) preflightCache.delete(reqId);
    }

    callback({ responseHeaders: headers });
  };

  ses.webRequest.onBeforeSendHeaders(urlsFilter, beforeSend);
  ses.webRequest.onHeadersReceived(urlsFilter, onHeaders);

  removeHandlers = () => {
    // Remove the specific listeners we added
    ses.webRequest.onBeforeSendHeaders(null as any, beforeSend);
    ses.webRequest.onHeadersReceived(null as any, onHeaders);
  };
}
