export type ApiPathValue = string | number

/**
 * Tag for an API path template: each interpolated value becomes exactly one
 * percent-encoded path segment, so a value cannot add segments, a query or a
 * fragment. An empty, `.` or `..` value is refused because URL parsing removes
 * or resolves such a segment, whatever its encoding.
 */
export function apiPath(literals: TemplateStringsArray, ...values: ApiPathValue[]): string {
  return values.reduce<string>(
    (path, value, index) => path + pathSegment(value) + literals[index + 1],
    literals[0],
  )
}

function pathSegment(value: ApiPathValue): string {
  const segment = String(value)
  if (segment === '' || segment === '.' || segment === '..') {
    throw new Error(`Refusing ${JSON.stringify(segment)} as an API path segment`)
  }

  return encodeURIComponent(segment)
}
