import * as Sentry from '@sentry/react';

Sentry.init({
  enabled: !BaanderAppInfo.debug,
  dsn: import.meta.env.SENTRY_DSN,
  integrations: [
    Sentry.browserTracingIntegration(),
  ],
  // Performance Monitoring
  tracesSampleRate: 0.05, //  Capture 100% of the transactions
  // Set 'tracePropagationTargets' to control for which URLs distributed tracing should be enabled
  tracePropagationTargets: ['localhost', /^https:\/\/baander\.test\/api/],
});


import ReactDOM from 'react-dom/client';
import App from './App';
import { Provider } from 'react-redux';
import { store } from '@/store';
import './index.css';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
// @ts-ignore
import { Ziggy } from './ziggy.js';
// @ts-ignore
globalThis.Ziggy = Ziggy;
import './bootstrap.ts';


const queryClient = new QueryClient();


ReactDOM.createRoot(document.getElementById('baanderapproot') as HTMLElement).render(
  <QueryClientProvider client={queryClient}>
    <Provider store={store}>
      <App/>
    </Provider>
  </QueryClientProvider>,
);
