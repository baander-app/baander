import { defineConfig } from 'orval';
import { normalizeOpenApiNullSchemas } from './openapi-transformer';

export default defineConfig({
  baander: {
    input: {
      target: '../../openapi.json',
      override: {
        transformer: normalizeOpenApiNullSchemas,
      },
    },
    output: {
      target: './src/shared/api-client/gen/endpoints/index.ts',
      client: 'react-query',
      mock: false,
      urlEncodeParameters: true,
      override: {
        fetch: {
          includeHttpResponseReturnType: false,
        },
        mutator: {
          path: './src/shared/api-client/axios-instance.ts',
          name: 'customInstance',
        },
        operations: {
          get_video_playback_preview: {
            mutator: {
              path: './src/shared/api-client/axios-instance.ts',
              name: 'blobInstance',
            },
          },
        },
      },
    },
  },
});
