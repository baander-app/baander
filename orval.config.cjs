import { defineConfig } from 'orval';

export default defineConfig({
  baander: {
    input: {
      target: './api.json',
    },
    output: {
      mode: 'tags-split',
      allParamsOptional: false,
      prettier: true,
      target: './resources/app/libs/api-client/gen/endpoints',
      schemas: './resources/app/libs/api-client/gen/models',
      client: 'react-query',
      override: {
        mutator: {
          path: './resources/app/libs/api-client/axios-instance.ts',
          name: 'customInstance',
        },
        query: {
          useQuery: true,
          useSuspenseQuery: true,
          useSuspenseInfiniteQuery: true,
          useInfinite: true,
          useInfiniteQueryParam: 'limit',
        },
      },
    },
  },
});