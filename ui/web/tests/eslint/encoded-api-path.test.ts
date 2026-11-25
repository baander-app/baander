// @vitest-environment node
import { Linter } from 'eslint'
import { describe, expect, it } from 'vitest'
import { encodedApiPath } from '../../eslint/encoded-api-path.js'

function findings(code: string): string[] {
  const linter = new Linter({ configType: 'flat' })
  const messages = linter.verify(code, [
    {
      files: ['**/*.js'],
      plugins: {
        baander: {
          rules: {
            'encoded-api-path': encodedApiPath,
          },
        },
      },
      rules: {
        'baander/encoded-api-path': 'error',
      },
    },
  ], { filename: 'src/features/library/api/library-api.js' })

  return messages.map((message) => message.ruleId === null ? message.message : code.slice(
    sourceOffset(code, message.line, message.column),
    sourceOffset(code, message.endLine ?? message.line, message.endColumn ?? message.column),
  ))
}

function sourceOffset(code: string, line: number, column: number): number {
  const lines = code.split('\n')
  const before = lines.slice(0, line - 1).reduce((length, text) => length + text.length + 1, 0)

  return before + column - 1
}

describe('baander/encoded-api-path', () => {
  it('reports each raw value interpolated into an API path', () => {
    expect(findings('client.delete(`/api/genres/${slug}/albums/${albumId}`)')).toEqual(['slug', 'albumId'])
  })

  it('reports a raw value in an API path that is not passed to a request', () => {
    expect(findings('const coverUrl = `/api/albums/${albumPublicId}/cover`')).toEqual(['albumPublicId'])
  })

  it('accepts a template built with apiPath', () => {
    expect(findings('client.delete(apiPath`/api/genres/${slug}/albums/${albumId}`)')).toEqual([])
  })

  it('accepts a value that the call site already encodes', () => {
    expect(findings('client.get(`/api/admin/logs/entries/${encodeURIComponent(id)}`)')).toEqual([])
  })

  it('leaves values after the query or fragment delimiter to the query encoder', () => {
    expect(findings('client.get(`/api/user/recent?${params}`)')).toEqual([])
    expect(findings('client.get(`/api/songs/${id}?include=${fields}`)')).toEqual(['id'])
  })

  it('reports a template tagged with another function', () => {
    expect(findings('client.get(String.raw`/api/libraries/${id}`)')).toEqual(['id'])
  })

  it('ignores templates that do not start with an API path', () => {
    expect(findings('const label = `/music/albums/${publicId}`')).toEqual([])
  })
})
