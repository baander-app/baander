import { describe, expect, it } from 'vitest';
import { normalizeOpenApiNullSchemas } from '../openapi-transformer';

describe('OpenAPI null schemas', () => {
  it('preserves nullable reference and scalar unions for client generation', () => {
    const nullSchema = { type: 'object', nullable: true, enum: [null] };
    const document = {
      components: {
        schemas: {
          Preview: { oneOf: [{ $ref: '#/components/schemas/FileDeletion' }, nullSchema] },
          Choice: { anyOf: [{ type: 'boolean' }, { type: 'integer' }, nullSchema] },
        },
      },
    };

    expect(normalizeOpenApiNullSchemas(document)).toEqual({
      components: {
        schemas: {
          Preview: { oneOf: [{ $ref: '#/components/schemas/FileDeletion' }], nullable: true },
          Choice: { anyOf: [{ type: 'boolean' }, { type: 'integer' }], nullable: true },
        },
      },
    });
    expect(document.components.schemas.Preview.oneOf[1]).toEqual({
      type: 'object', nullable: true, enum: [null],
    });
  });

  it('keeps ordinary schemas and payload examples unchanged', () => {
    const document = {
      components: {
        schemas: {
          Object: { type: 'object', nullable: true },
          Choice: { type: 'string', nullable: true, enum: ['first', null] },
          Payload: {
            type: 'object',
            example: { type: 'object', nullable: true, enum: [null] },
            default: { type: 'object', nullable: true, enum: [null] },
          },
        },
      },
    };

    expect(normalizeOpenApiNullSchemas(document)).toEqual(document);
  });

  it('normalizes a schema property named default without changing its default payload', () => {
    const nullSchema = { type: 'object', nullable: true, enum: [null] };
    const document = {
      components: {
        schemas: {
          Setting: {
            properties: {
              default: { oneOf: [{ type: 'boolean' }, nullSchema], default: nullSchema },
            },
          },
        },
      },
    };

    expect(normalizeOpenApiNullSchemas(document)).toEqual({
      components: {
        schemas: {
          Setting: {
            properties: {
              default: { oneOf: [{ type: 'boolean' }], nullable: true, default: nullSchema },
            },
          },
        },
      },
    });
  });

  it('preserves null branches with constraints that can exclude null', () => {
    const document = {
      components: {
        schemas: {
          Constrained: {
            oneOf: [
              { type: 'boolean' },
              { type: 'object', nullable: true, enum: [null], not: { enum: [null] } },
            ],
          },
        },
      },
    };

    expect(normalizeOpenApiNullSchemas(document)).toEqual(document);
  });
});
