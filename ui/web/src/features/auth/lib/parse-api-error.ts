import { AxiosError } from 'axios';

/**
 * Extract a human-readable error message from an API error.
 *
 * Handles two backend error envelope shapes:
 * - OAuth-style: { error: string, error_description: string }
 * - Project-style: { error: { code: string, message: string } }
 *
 * Also checks for known error codes that signal special behavior (e.g. AUTH_TOTP_REQUIRED).
 */
interface OAuthStyleError {
  error: string
  error_description?: string
}

interface ProjectStyleError {
  error: {
    code: string
    message: string
  }
}

function isOAuthStyle(data: unknown): data is OAuthStyleError {
  return typeof data === 'object' && data !== null && typeof (data as OAuthStyleError).error === 'string'
}

function isProjectStyle(data: unknown): data is ProjectStyleError {
  return (
    typeof data === 'object' &&
    data !== null &&
    typeof (data as ProjectStyleError).error === 'object' &&
    (data as ProjectStyleError).error !== null &&
    typeof (data as ProjectStyleError).error.code === 'string' &&
    typeof (data as ProjectStyleError).error.message === 'string'
  )
}

export interface ParsedApiError {
  message: string
  code: string | null
}

export function parseApiError(err: unknown, fallback: string): ParsedApiError {
  if (!(err instanceof AxiosError)) return { code: null, message: fallback };
  const data = err.response?.data;

  if (isOAuthStyle(data)) {
    return {
      code: data.error,
      message: data.error_description ?? data.error,
    }
  }

  if (isProjectStyle(data)) {
    return {
      code: data.error.code,
      message: data.error.message,
    }
  }

  return { code: null, message: fallback }
}
