import { defineConfig } from 'orval';

export default defineConfig({
  baander: {
    hooks: {},
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
          useInfiniteQueryParam: 'page',
          infinite: {
            useInfiniteQueryParam: 'page',
            // Auto-generate getNextPageParam for all infinite queries
            getNextPageParam: (lastPage) => {
              const pagination = lastPage?.pagination;
              if (!pagination) return undefined;
              const currentPage = pagination.page;
              const totalPages = Math.ceil(pagination.total / pagination.per_page);
              return currentPage < totalPages ? currentPage + 1 : undefined;
            },
          },
        },
      },
    },
  },
});
