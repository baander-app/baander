import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';

import { Provider } from 'react-redux';
import { store } from '@/app/store';
import './index.css';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client';
// @ts-ignore
import { Ziggy } from './ziggy.js';
import './bootstrap.ts';
import './common/i18n.ts';
import { DateFormatterProvider } from '@/app/providers/dayjs-provider.tsx';
import { createIDBPersister, queryClient } from '@/app/common/react-query.ts';
import { RadixProvider } from '@/app/providers/radix-provider.tsx';
import { Reset } from '@radix-ui/themes';
import { TestModeProvider } from '@/app/providers/test-mode-provider.tsx';
import { DevSupport } from '@react-buddy/ide-toolbox';
import { ComponentPreviews, useInitial } from './dev';
// @ts-ignore
globalThis.Ziggy = Ziggy;

const reactQueryPersister = createIDBPersister();

ReactDOM.createRoot(document.getElementById('baanderapproot') as HTMLElement).render(
  <React.StrictMode>
    <TestModeProvider>
      <PersistQueryClientProvider
        client={queryClient}
        persistOptions={{buster: 'baander', persister: reactQueryPersister}}
      >
        <Provider store={store}>
          <DateFormatterProvider>
            <RadixProvider>
              <Reset>
                <DevSupport
                  ComponentPreviews={ComponentPreviews}
                  useInitialHook={useInitial}
                >
                  <App/>
                </DevSupport>
              </Reset>
            </RadixProvider>
          </DateFormatterProvider>
        </Provider>
      </PersistQueryClientProvider>
    </TestModeProvider>
  </React.StrictMode>,
);
