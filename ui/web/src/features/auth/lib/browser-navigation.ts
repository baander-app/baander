/**
 * Leaves the app for a URL outside the router, such as an OAuth client's redirect URI. Kept in
 * its own module so tests can observe the navigation instead of performing it.
 */
export function leaveAppFor(url: string): void {
  window.location.assign(url)
}
