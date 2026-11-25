/**
 * Reports a value interpolated into the path of an `/api/` template literal.
 * A template tagged with `apiPath` encodes every value, and a value wrapped in
 * `encodeURIComponent` is already encoded. Values after the first `?` or `#`
 * belong to the query or fragment, which this rule does not check.
 *
 * @type {import('eslint').Rule.RuleModule}
 */
export const encodedApiPath = {
  meta: {
    type: 'problem',
    docs: {
      description: 'Require API path templates to encode each interpolated value as one path segment',
    },
    messages: {
      raw: 'Build API paths with apiPath`…` from @/shared/api-client/api-path, which encodes each value as one path segment.',
    },
    schema: [],
  },
  create(context) {
    return {
      TemplateLiteral(node) {
        if (isApiPathTag(node) || !node.quasis[0].value.raw.startsWith('/api/')) return

        for (const [index, expression] of node.expressions.entries()) {
          if (/[?#]/.test(node.quasis[index].value.raw)) return
          if (!isEncodeCall(expression)) context.report({ node: expression, messageId: 'raw' })
        }
      },
    }
  },
}

function isApiPathTag(node) {
  return node.parent.type === 'TaggedTemplateExpression'
    && node.parent.quasi === node
    && node.parent.tag.type === 'Identifier'
    && node.parent.tag.name === 'apiPath'
}

function isEncodeCall(expression) {
  return expression.type === 'CallExpression'
    && expression.callee.type === 'Identifier'
    && expression.callee.name === 'encodeURIComponent'
}
