import { DefaultError, DefaultOptions, Query, QueryCache, QueryClient } from '@tanstack/react-query';
import { notifications } from '@mantine/notifications';
import { get, set, del } from 'idb-keyval';
import {
  PersistedClient,
  Persister,
} from '@tanstack/react-query-persist-client';
import { Env } from '@/common/env.ts';

const queryCache = new QueryCache({
  onError: (error: DefaultError, query: Query<unknown, unknown, unknown>) => {
    if (query.state.data !== undefined) {
      notifications.show({
        title: 'Query error',
        message: `${error.message}`,
      });
    }
  },
});

const defaultOptions: DefaultOptions = {
  mutations: {
    retry: Env.isProduction(),
  },
  queries: {
    refetchOnWindowFocus: true,
    retry: Env.isProduction(),
    staleTime: 1000 * 5,
  },
};

export const queryClient = new QueryClient({
  defaultOptions,
  queryCache,
});

export function createIDBPersister(idbValidKey: IDBValidKey = 'reactQuery') {
  return {
    persistClient: async (client: PersistedClient) => {
      await set(idbValidKey, client);
    },
    restoreClient: async () => {
      return await get<PersistedClient>(idbValidKey);
    },
    removeClient: async () => {
      await del(idbValidKey);
    },
  } as Persister;
}