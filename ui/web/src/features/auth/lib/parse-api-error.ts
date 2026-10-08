import { AxiosError } from 'axios';

/**
 * Extract a human-readable error message from an API error.
 *
 * Handles two backend error envelope shapes:
 * - OAuth-style: { error: string, error_description: string }
 * - Project-style: { error: { code: string | number, message: string } }
 *
 * Also checks for known error codes that signal special behavior (e.g. AUTH_TOTP_REQUIRED).
 */
interface OAuthStyleError {
  error: string
  error_description?: string
}

interface ProjectStyleError {
  error: {
    code: string | number
    message: string
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isOAuthStyle(data: unknown): data is OAuthStyleError {
  return isRecord(data) && typeof data.error === 'string' &&
    (data.error_description === undefined || typeof data.error_description === 'string')
}

function isProjectStyle(data: unknown): data is ProjectStyleError {
  if (!isRecord(data) || !isRecord(data.error)) return false
  const { code, message } = data.error
  return (typeof code === 'string' || (typeof code === 'number' && Number.isInteger(code))) &&
    typeof message === 'string'
}

export interface ParsedApiError {
  message: string
  code: string | number | null
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

/**
 * The messages a 422 validation error carries for one field in `error.details`.
 *
 * Any other error, or a field without string messages, gives an empty list.
 */
export function parseFieldViolations(err: unknown, field: string): string[] {
  if (!(err instanceof AxiosError) || err.response?.status !== 422) return []
  const data: unknown = err.response.data
  const details = isRecord(data) && isRecord(data.error) ? data.error.details : undefined
  const messages = isRecord(details) && Object.hasOwn(details, field) ? details[field] : undefined

  return Array.isArray(messages) ? messages.filter((message): message is string => typeof message === 'string') : []
}
