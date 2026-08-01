const path = require('path');
const { getDefaultConfig, mergeConfig } = require('@react-native/metro-config');
const { metroExtraNodeModules } = require('./scripts/paths');

const defaultConfig = getDefaultConfig(__dirname);

const config = {
  watchFolders: [
    path.resolve(__dirname, '../shared'),
  ],

  resolver: {
    // Let the ../shared watchFolder's imports fall back to this project's
    // node_modules (shared is a separate yarn project without every RN dep).
    nodeModulesPaths: [
      path.resolve(__dirname, 'node_modules'),
      path.resolve(__dirname, '../shared/node_modules'),
    ],
    extraNodeModules: metroExtraNodeModules(),
  },

  transformer: {
    getTransformOptions: async () => ({
      transform: {
        experimentalImportSupport: false,
        inlineRequires: true,
      },
    }),
  },
};

module.exports = mergeConfig(defaultConfig, config);
