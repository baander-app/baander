<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $config->get('ui.title', config('app.name') . ' - API Docs') }}</title>

    <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css">

    <script>
      const originalFetch = window.fetch;

      const getToken = () => {
        const token = localStorage.getItem('baander_token');

        if (token) {
          return JSON.parse(token).accessToken.token;
        }
      };

      // intercept TryIt requests and add the XSRF-TOKEN header,
      // which is necessary for Sanctum cookie-based authentication to work correctly
      window.fetch = (url, options) => {
        const CSRF_TOKEN_COOKIE_KEY = 'XSRF-TOKEN';
        const CSRF_TOKEN_HEADER_KEY = 'X-XSRF-TOKEN';
        const getCookieValue = (key) => {
          const cookie = document.cookie.split(';').find((cookie) => cookie.trim().startsWith(key));
          return cookie?.split('=')[1];
        };

        const updateFetchHeaders = (
          headers,
          headerKey,
          headerValue,
        ) => {
          if (headers instanceof Headers) {
            headers.set(headerKey, headerValue);
          } else if (Array.isArray(headers)) {
            headers.push([headerKey, headerValue]);
          } else if (headers) {
            headers[headerKey] = headerValue;
          }
        };
        const csrfToken = getCookieValue(CSRF_TOKEN_COOKIE_KEY);

        const token = getToken();
        if (csrfToken) {
          const {headers = new Headers()} = options || {};
          updateFetchHeaders(headers, CSRF_TOKEN_HEADER_KEY, decodeURIComponent(csrfToken));

          if (token) {
            updateFetchHeaders(headers, 'Authorization', `Bearer ${token}`);
          }

          return originalFetch(url, {
            ...options,
            headers,
          });
        }

        return originalFetch(url, options);
      };
    </script>
    <style>
        #mosaic-provider-react-aria-0-1 > div > div > div > div.sl-flex > div.sl-flex.sl-flex-grow-0.sl-flex-shrink-0.sl-justify-self-end.sl-resize-x {
            /*background-color: #17282e;*/
        }
    </style>
</head>
<body style="height: 100vh; overflow-y: hidden">
<elements-api
        id="docs"
        tryItCredentialsPolicy="{{ $config->get('ui.try_it_credentials_policy', 'include') }}"
        router="hash"
        @if($config->get('ui.hide_try_it')) hideTryIt="true" @endif
        logo="{{ $config->get('ui.logo') }}"
        layout="responsive"
/>
<script>
  (async () => {
    const docs = document.getElementById('docs');
    docs.apiDescriptionDocument = @json($spec);
  })();
</script>
</body>
</html>
