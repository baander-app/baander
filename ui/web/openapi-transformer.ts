/** Fold swagger-php's null-only branches into the nullable union form Orval supports. */
export function normalizeOpenApiNullSchemas<T>(document: T): T {
  const normalized = structuredClone(document);

  function visit(value: unknown, schemaMap = false): void {
    if (value === null || typeof value !== 'object') return;
    if (Array.isArray(value)) {
      value.forEach((child) => visit(child));
      return;
    }

    const schema = value as Record<string, unknown>;
    for (const keyword of ['oneOf', 'anyOf']) {
      const alternatives = schema[keyword];
      if (!Array.isArray(alternatives)) continue;

      const remaining = alternatives.filter((alternative: unknown) => {
        if (alternative === null || typeof alternative !== 'object') return true;
        const branch = alternative as Record<string, unknown>;
        return !(
          Object.keys(branch).every((key) => ['type', 'nullable', 'enum'].includes(key)) &&
          branch.type === 'object' &&
          branch.nullable === true &&
          Array.isArray(branch.enum) &&
          branch.enum.length === 1 &&
          branch.enum[0] === null
        );
      });
      if (remaining.length > 0 && remaining.length !== alternatives.length) {
        schema[keyword] = remaining;
        schema.nullable = true;
      }
    }

    for (const [key, child] of Object.entries(schema)) {
      if (schemaMap || !['example', 'examples', 'default', 'enum'].includes(key)) {
        visit(child, key === 'properties' || key === 'schemas');
      }
    }
  }

  visit(normalized);
  return normalized;
}
