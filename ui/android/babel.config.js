module.exports = {
  presets: ['module:@react-native/babel-preset'],
  plugins: [
    'nativewind/babel',
    'react-native-reanimated/plugin',
    [
      'module-resolver',
      {
        root: ['./src'],
        extensions: ['.android.js', '.android.tsx', '.js', '.ts', '.tsx', '.json'],
        alias: {
          '@': './src',
          '@baander/shared': '../shared',
        },
      },
    ],
  ],
};
