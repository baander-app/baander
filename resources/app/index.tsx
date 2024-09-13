import React from 'react';
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
import './i18n.ts';
import { DateFormatterProvider } from '@/providers/dayjs-provider.tsx';

const queryClient = new QueryClient();

ReactDOM.createRoot(document.getElementById('baanderapproot') as HTMLElement).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <Provider store={store}>
        <DateFormatterProvider>
          <App/>
        </DateFormatterProvider>
      </Provider>
    </QueryClientProvider>
  </React.StrictMode>,
);
