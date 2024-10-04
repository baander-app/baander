import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import { Provider } from 'react-redux';
import { store } from '@/store';
import './index.css';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client';
// @ts-ignore
import { Ziggy } from './ziggy.js';
// @ts-ignore
globalThis.Ziggy = Ziggy;
import './bootstrap.ts';
import './common/i18n.ts';
import { DateFormatterProvider } from '@/providers/dayjs-provider.tsx';
import { queryClient, createIDBPersister } from '@/common/react-query.ts';


const reactQueryPersister = createIDBPersister();

ReactDOM.createRoot(document.getElementById('baanderapproot') as HTMLElement).render(
  <React.StrictMode>
    <PersistQueryClientProvider
      client={queryClient}
      persistOptions={{ buster: 'baander', persister: reactQueryPersister }}
    >
      <Provider store={store}>
        <DateFormatterProvider>
          <App/>
        </DateFormatterProvider>
      </Provider>
    </PersistQueryClientProvider>
  </React.StrictMode>,
);
