const { jestModuleNameMapper } = require('./scripts/paths');

module.exports = {
  preset: 'react-native',
  moduleNameMapper: jestModuleNameMapper(),
  // Jest appends these to the preset's setup files.
  setupFiles: ['react-native-gesture-handler/jestSetup', '<rootDir>/jest.setup.js'],
  // `../shared` sources have no node_modules of their own; resolve their imports from this app.
  modulePaths: ['<rootDir>/node_modules'],
  testPathIgnorePatterns: ['/node_modules/', '/android/', '/ios/'],
  // The preset only transforms React Native packages; React Navigation ships untransformed ESM and
  // react-native-tvos ships Flow in @react-native-tvos/*.
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community|-tvos)?|@react-navigation)/)',
  ],
};
