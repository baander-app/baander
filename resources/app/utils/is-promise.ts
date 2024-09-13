export function isPromise(v: unknown): v is Promise<unknown> {
  // @ts-ignore
  return v !== null && Boolean(v && typeof v.then === 'function');
}