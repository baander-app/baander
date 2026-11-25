import js from '@eslint/js'
import globals from 'globals'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import tseslint from 'typescript-eslint'
import { defineConfig, globalIgnores } from 'eslint/config'
import { encodedApiPath } from './eslint/encoded-api-path.js'

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
  },
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: ['src/shared/api-client/gen/**', 'src/**/__tests__/**', 'src/**/*.test.{ts,tsx}'],
    plugins: {
      baander: {
        rules: {
          'encoded-api-path': encodedApiPath,
        },
      },
    },
    rules: {
      'baander/encoded-api-path': 'error',
    },
  },
])
