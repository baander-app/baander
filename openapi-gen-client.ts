import { createClient } from '@hey-api/openapi-ts';
import * as parser from 'php-parser';
import fs from 'fs';
import path from 'path';

// Create an instance of the PHP parser
const php = new parser.Engine({
  parser: {
    extractDoc: false,
    version: 803,
    suppressErrors: true,
  },
  ast: {
    withPositions: true,
  },
});

/**
 * Recursively scans a directory and retrieves PHP class names.
 * @param {string} dirPath - The directory path to scan.
 * @returns {string[]} - An array of class names.
 */
function scanClasses(dirPath: string): string[] {
  const classNames: string[] = [];

  function recursiveScan(currentPath: string) {
    const files = fs.readdirSync(currentPath);

    files.forEach(file => {
      const filePath = path.join(currentPath, file);
      const stat = fs.statSync(filePath);

      if (stat.isDirectory()) {
        recursiveScan(filePath);
      } else if (file.endsWith('.php')) {
        const content = fs.readFileSync(filePath, 'utf8');
        try {
          const ast = php.parseCode(content, path.basename(filePath));
          extractClassNames(ast, classNames);
        } catch (err) {
          console.error(`Error parsing ${filePath}:`, err);
        }
      }
    });
  }

  recursiveScan(dirPath);
  return classNames;
}

/**
 * Traverses the AST to extract class names.
 * @param {any} node - The AST node.
 * @param {string[]} classNames - The array to store class names.
 */
function extractClassNames(node: any, classNames: string[]) {
  if (node.kind === 'class') {
    classNames.push(node.name.name);
  }

  for (const key in node) {
    if (node[key] && typeof node[key] === 'object') {
      extractClassNames(node[key], classNames);
    }
  }
}

// Scan directories for classes
const requestsDir = path.join(__dirname, 'app', 'Http', 'Requests');
const resourcesDir = path.join(__dirname, 'app', 'Http', 'Resources');

// Define exceptions and scan classes
const exceptions = [
  'ModelNotFoundException',
  'AuthenticationException',
  'ValidationException',
  'AuthorizationException',
];
const scannedRequestsClasses = scanClasses(requestsDir);
const scannedResourcesClasses = scanClasses(resourcesDir);
const classesArray = [
  ...scannedRequestsClasses,
  ...scannedResourcesClasses,
  ...exceptions,
];
const classesPattern = classesArray.join('|').replace(/\./g, '\\.').replace(/\//g, '\\/');

const outDir = `${__dirname}/resources/app/api-client/gen`;

if (fs.existsSync(outDir)) {
  fs.rmdirSync(outDir, { recursive: true});
}

createClient({
  client: '@hey-api/client-axios',
  input: {
    path: `${__dirname}/api.json`,
    include: `^(#\/paths\/api(\/.*)?|#\/paths\/webauthn(\/.*)?|#\/components\/schemas\/(${classesPattern}|LibraryType))$`, // Explicitly include LibraryType
  },
  output: outDir,
  experimentalParser: true,
  plugins: [
    '@hey-api/schemas',
    '@hey-api/sdk',
    {
      enums: 'javascript',
      name: '@hey-api/typescript',
    },
    '@tanstack/react-query',
    'zod',
  ],
});