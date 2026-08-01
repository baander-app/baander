const { babelAliases } = require('./scripts/paths');

module.exports = {
  presets: ['module:@react-native/babel-preset'],
  plugins: [
    'react-native-reanimated/plugin',
    [
      'module-resolver',
      {
        root: ['./src'],
        extensions: ['.ios.js', '.android.js', '.ios.tsx', '.android.tsx', '.js', '.ts', '.tsx', '.json'],
        alias: babelAliases(),
      },
    ],
  ],
};
