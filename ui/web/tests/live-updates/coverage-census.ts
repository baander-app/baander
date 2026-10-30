import ts from 'typescript'

export interface QueryConsumer {
  file: string
  call: string
  variant: string
  kind: 'query' | 'reader'
}

function compact(node: ts.Node, source: ts.SourceFile): string {
  const scanner = ts.createScanner(ts.ScriptTarget.Latest, true, ts.LanguageVariant.JSX, node.getText(source))
  const tokens: string[] = []
  while (scanner.scan() !== ts.SyntaxKind.EndOfFileToken) tokens.push(scanner.getTokenText())
  return tokens.join('')
}

export function findConsumers(file: string, text: string): QueryConsumer[] {
  if (file.includes('/gen/') || /(?:\.test\.|\.spec\.|\/__tests__\/)/.test(file)) return []
  const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
  const imports = new Map<string, { name: string; module: string }>()
  const result: QueryConsumer[] = []
  const declarations = new Map<string, ts.Expression>()
  function collect(node: ts.Node) {
    if (ts.isVariableDeclaration(node) && ts.isIdentifier(node.name) && node.initializer) declarations.set(node.name.text, node.initializer)
    ts.forEachChild(node, collect)
  }
  collect(source)
  function keyVariant(node: ts.Expression): string {
    const expression = ts.isIdentifier(node) ? declarations.get(node.text) : undefined
    const base = expression ? `${compact(node, source)}=${compact(expression, source)}` : compact(node, source)
    const references = new Map<string, string>()
    function referenced(child: ts.Node) {
      if (ts.isIdentifier(child) && child !== node && /key/i.test(child.text)) {
        const declaration = declarations.get(child.text)
        if (declaration) references.set(child.text, compact(declaration, source))
      }
      ts.forEachChild(child, referenced)
    }
    referenced(node)
    return base + [...references].map(([name, value]) => `;${name}=${value}`).join('')
  }
  for (const statement of source.statements) {
    if (!ts.isImportDeclaration(statement) || !ts.isStringLiteral(statement.moduleSpecifier)) continue
    const module = statement.moduleSpecifier.text
    const clause = statement.importClause
    if (clause?.name) imports.set(clause.name.text, { name: 'default', module })
    if (clause?.namedBindings && ts.isNamedImports(clause.namedBindings)) {
      for (const binding of clause.namedBindings.elements) {
        imports.set(binding.name.text, { name: binding.propertyName?.text ?? binding.name.text, module })
      }
    } else if (clause?.namedBindings && ts.isNamespaceImport(clause.namedBindings)) {
      imports.set(clause.namedBindings.name.text, { name: '*', module })
    }
  }
  function visit(node: ts.Node) {
    if (ts.isCallExpression(node)) {
      if (ts.isIdentifier(node.expression) && node.expression.text === 'fetch') {
        result.push({ file, call: 'fetch', variant: node.arguments[0] ? compact(node.arguments[0], source) : 'unknown', kind: 'reader' })
      }
      let binding: { name: string; module: string } | undefined
      if (ts.isIdentifier(node.expression)) binding = imports.get(node.expression.text)
      if (ts.isPropertyAccessExpression(node.expression) && ts.isIdentifier(node.expression.expression)) {
        const owner = imports.get(node.expression.expression.text)
        if (owner?.name === '*') binding = { ...owner, name: node.expression.name.text }
        if (owner?.name === 'AXIOS_INSTANCE' && ['get', 'head', 'request'].includes(node.expression.name.text)) {
          result.push({ file, call: `AXIOS_INSTANCE.${node.expression.name.text}`, variant: node.arguments[0] ? compact(node.arguments[0], source) : 'unknown', kind: 'reader' })
        }
      }
      if (binding?.module === '@tanstack/react-query' && ['useQuery', 'useInfiniteQuery', 'useSuspenseQuery', 'useSuspenseInfiniteQuery', 'useQueries', 'useSuspenseQueries'].includes(binding.name)) {
        const options = node.arguments[0]
        const key = options && ts.isObjectLiteralExpression(options)
          ? options.properties.find((property) => (ts.isPropertyAssignment(property) || ts.isShorthandPropertyAssignment(property)) && property.name.getText(source) === 'queryKey')
          : undefined
        result.push({ file, call: binding.name, variant: key && ts.isPropertyAssignment(key) ? keyVariant(key.initializer) : key && ts.isShorthandPropertyAssignment(key) ? keyVariant(key.name) : options ? compact(options, source) : 'unknown', kind: 'query' })
      }
      if (binding?.module.includes('/gen/endpoints') && (/^useGet/.test(binding.name) || /^get(?!Get)/.test(binding.name))) {
        const overrides: string[] = []
        function inspect(argument: ts.Node) {
          if (ts.isPropertyAssignment(argument) && argument.name.getText(source) === 'queryKey') overrides.push(keyVariant(argument.initializer))
          if (ts.isShorthandPropertyAssignment(argument) && argument.name.text === 'queryKey') overrides.push(keyVariant(argument.name))
          ts.forEachChild(argument, inspect)
        }
        node.arguments.forEach(inspect)
        result.push({ file, call: binding.name, variant: 'generated' + overrides.map((key) => `;queryKey=${key}`).join(''), kind: binding.name.startsWith('use') ? 'query' : 'reader' })
      }
      if (ts.isPropertyAccessExpression(node.expression) && ['fetchQuery', 'fetchInfiniteQuery', 'prefetchQuery', 'prefetchInfiniteQuery', 'ensureQueryData', 'ensureInfiniteQueryData'].includes(node.expression.name.text)) {
        result.push({ file, call: node.expression.name.text, variant: node.arguments[0] ? compact(node.arguments[0], source) : 'unknown', kind: 'query' })
      }
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
  return result
}

export function consumerId(consumer: QueryConsumer): string {
  return `${consumer.file}#${consumer.call}:${consumer.variant}`
}

export function unclassified(consumers: QueryConsumer[], registered: readonly string[]): string[] {
  const known = new Set(registered)
  return [...new Set(consumers.map(consumerId).filter((id) => !known.has(id)))]
}
