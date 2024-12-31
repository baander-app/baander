export function assertIsDefined<T>(val: T, paramName: string): asserts val is NonNullable<T> {
  if (val === undefined || val === null) {
    throw new Error(`Parameter "${paramName}" is required but not found`);
  }
}