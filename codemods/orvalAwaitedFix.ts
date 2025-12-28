#!/usr/bin/env node
/**
 * AST-based codemod that replaces Awaited<ReturnType<typeof fn>> with the actual return type.
 */

import { readdirSync, readFileSync, writeFileSync } from 'fs';
import { join } from 'path';
import ts from 'typescript';

const args = process.argv.slice(2);
const DRY = args.includes('--dry');
const ENDPOINTS_DIR = args.find(arg => !arg.startsWith('--')) ?? './resources/app/libs/api-client/gen/endpoints';

main().catch((e) => {
  console.error(e);
  process.exit(1);
});

async function main() {
  const files = walk(ENDPOINTS_DIR);
  let needsFix = 0;

  for (const file of files) {
    const src = readFileSync(file, 'utf8');
    const out = transform(src, file);
    if (out === src) continue;

    needsFix++;
    if (DRY) {
      console.log(`Would modify: ${file}`);
      continue;
    }

    writeFileSync(file, out, 'utf8');
    console.log(`✅ ${file}`);
  }

  if (DRY && needsFix) {
    console.log(`\n--dry run: ${needsFix} file(s) would be changed`);
  }
}

function transform(code: string, filepath: string): string {
  // Build function -> type map using regex to find customInstance calls
  const fnToType = new Map<string, string>();
  const fnRegex = /export const (\w+) = [\s\S]*?customInstance<(\w+)>/g;
  let match;
  while ((match = fnRegex.exec(code)) !== null) {
    const fnName = match[1];
    const typeName = match[2];
    if (!fnName.startsWith('get')) {
      fnToType.set(fnName, typeName);
    }
  }

  if (fnToType.size === 0) return code;

  // Use TypeScript compiler API for AST transformation
  const sourceFile = ts.createSourceFile(
    filepath,
    code,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TS
  );

  const changes: { start: number; end: number; text: string }[] = [];

  function visit(node: ts.Node) {
    // Find type references like Awaited<ReturnType<typeof fn>>
    if (ts.isTypeReferenceNode(node)) {
      const typeText = node.typeName.getText(sourceFile);

      // Check if this is Awaited<ReturnType<typeof fn>>
      if (typeText === 'Awaited') {
        const typeArg = node.typeArguments?.[0];
        if (typeArg && ts.isTypeReferenceNode(typeArg) && typeArg.typeName.getText(sourceFile) === 'ReturnType') {
          const returnTypeArg = typeArg.typeArguments?.[0];
          if (returnTypeArg && ts.isTypeQueryNode(returnTypeArg) && (returnTypeArg as any).exprName) {
            const fnName = (returnTypeArg as any).exprName.getText(sourceFile);
            const actualType = fnToType.get(fnName);

            if (actualType) {
              // Found Awaited<ReturnType<typeof fn>> - replace with actualType
              changes.push({
                start: node.getStart(sourceFile),
                end: node.getEnd(),
                text: actualType
              });
            }
          }
        }
      }
    }

    node.forEachChild(visit);
  }

  visit(sourceFile);

  if (changes.length === 0) return code;

  // Apply changes in reverse order to maintain positions
  const sortedChanges = [...changes].sort((a, b) => b.start - a.start);
  let result = code;

  for (const change of sortedChanges) {
    result = result.substring(0, change.start) + change.text + result.substring(change.end);
  }

  return result;
}

function walk(root: string): string[] {
  const files: string[] = [];
  const items = readdirSync(root, { withFileTypes: true });
  for (const item of items) {
    const p = join(root, item.name);
    if (item.isDirectory()) files.push(...walk(p));
    else if (item.name.endsWith('.ts')) files.push(p);
  }
  return files;
}
