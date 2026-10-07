/**
 * Reads the password reset token from a URL fragment such as `#token=abc`.
 *
 * Reset emails put the token in the fragment because browsers never send a fragment to a
 * server, so it stays out of access logs and Referer headers.
 */
export function readResetToken(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token')?.trim()

  return token ? token : null
}
