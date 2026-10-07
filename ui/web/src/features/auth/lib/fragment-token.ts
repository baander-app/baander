/**
 * Reads a single-use token from a URL fragment such as `#token=abc`.
 *
 * Password reset and email verification links put the token in the fragment because browsers
 * never send a fragment to a server, so it stays out of access logs and Referer headers.
 */
export function readFragmentToken(hash: string): string | null {
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token')?.trim()

  return token ? token : null
}
