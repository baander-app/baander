export function stringify(value: unknown): string | undefined {
  if (value) {
    return JSON.stringify(value);
  }
}