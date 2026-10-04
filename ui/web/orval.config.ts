import { defineConfig } from 'orval';

export default defineConfig({
  baander: {
    input: {
      target: '../../openapi.json',
    },
    output: {
      target: './src/shared/api-client/gen/endpoints/index.ts',
      client: 'react-query',
      mock: false,
      override: {
        fetch: {
          includeHttpResponseReturnType: false,
        },
        mutator: {
          path: './src/shared/api-client/axios-instance.ts',
          name: 'customInstance',
        },
      },
    },
  },
});
