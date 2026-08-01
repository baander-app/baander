// Single source of truth for module path aliases.
//
// Reads `tsconfig.json` `compilerOptions.paths` and exposes the alias map in
// the shapes Babel (babel-plugin-module-resolver), Metro
// (resolver.extraNodeModules), and Jest (moduleNameMapper) each expect.
//
// Change aliases in tsconfig.json only — every consumer re-derives from here.

const path = require('path');

const tsconfig = require('../tsconfig.json');
const projectRoot = path.resolve(__dirname, '..');

const FILE_EXT = /\.(t|j)sx?$/;

// Normalize tsconfig paths into a prefix -> relative-target map.
//   "@/*": ["./src/*"]                        -> { "@": "./src" }
//   "@baander/shared": ["../shared/index.ts"] -> { "@baander/shared": "../shared" }
//   "@baander/shared/*": ["../shared/*"]      -> (merged into the above)
function buildAliases(paths) {
  const aliases = {};
  for (const [key, targets] of Object.entries(paths)) {
    const prefix = key.replace(/\/\*$/, '');
    let target = targets[0].replace(/\/\*$/, '');
    if (FILE_EXT.test(target)) target = path.posix.dirname(target);
    if (!(prefix in aliases)) aliases[prefix] = target;
  }
  return aliases;
}

const aliases = buildAliases(tsconfig.compilerOptions.paths);

// Babel `babel-plugin-module-resolver` alias (values relative to the project root).
function babelAliases() {
  return { ...aliases };
}

// Metro `resolver.extraNodeModules` (absolute paths).
function metroExtraNodeModules() {
  const modules = {};
  for (const [prefix, rel] of Object.entries(aliases)) {
    modules[prefix] = path.resolve(projectRoot, rel);
  }
  return modules;
}

// Jest `moduleNameMapper` (regex -> <rootDir>-relative paths).
function jestModuleNameMapper() {
  const mapper = {};
  for (const [prefix, rel] of Object.entries(aliases)) {
    const escaped = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const rootRel = rel.replace(/^\.\//, '');
    mapper[`^${escaped}$`] = `<rootDir>/${rootRel}`;
    mapper[`^${escaped}/(.*)$`] = `<rootDir>/${rootRel}/$1`;
  }
  return mapper;
}

module.exports = {
  aliases,
  babelAliases,
  metroExtraNodeModules,
  jestModuleNameMapper,
};
