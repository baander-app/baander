import React from 'react';
import ReactDOM from 'react-dom/client';

import App from './app';

const originalFetch = window.fetch;

const getToken = () => {
  try {
    const token = localStorage.getItem('baander_token');

    if (token) {
      return JSON.parse(token).accessToken;
    }
  } catch (error) {
    console.error(error)
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


ReactDOM.createRoot(document.getElementById('baanderdocs') as HTMLElement).render(
  <React.StrictMode>
    <App/>
  </React.StrictMode>
)