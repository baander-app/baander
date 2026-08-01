const { jestModuleNameMapper } = require('./scripts/paths');

module.exports = {
  preset: 'react-native',
  moduleNameMapper: jestModuleNameMapper(),
  testPathIgnorePatterns: ['/node_modules/', '/android/', '/ios/'],
};
