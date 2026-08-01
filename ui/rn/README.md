# Baander — React Native (tvOS + Android TV)

TV-first React Native client for [Baander](../../STRATEGY.md). Targets **Apple TV (tvOS)** and **Android TV**, built on the [`react-native-tvos`](https://github.com/react-native-tvos/react-native-tvos) fork (installed under the `react-native` package name). The TV UI lives in [`src/features/tv`](src/features/tv); the same JS drives both TV surfaces.

## Prerequisites

- **Node >= 22** and **Yarn 4** (`corepack enable`).
- **Apple TV (tvOS):** Xcode with the tvOS simulator. CocoaPods is installed via the [`Gemfile`](Gemfile) (`bundle exec pod install`).
- **Android TV:** Android SDK with an **Android TV** system image (AVD), plus:
  - **JDK 17** on `JAVA_HOME` (React Native 0.86 / AGP require it; JDK 21 will fail the Gradle build).
  - **API 37 (android-37)** installed. If your SDK directory is named differently, symlink it to `android-37`.
  - AGP 8.12 with `compileSdk 37` prints a known warning — safe to ignore.

## First-time setup

```bash
yarn install                       # install JS deps (also installs ../shared)
cd ios && bundle exec pod install  # macOS only — CocoaPods for the iOS target
```

`@baander/shared` (`../shared`) is a workspace dependency, wired into Metro via `watchFolders` + `resolver.nodeModulesPaths` so its imports resolve through this project's `node_modules`.

## Running

Start Metro in one terminal, then launch on a target in another:

```bash
yarn start        # Metro bundler
yarn tvos         # Apple TV simulator (iOS scheme Baander-tvOS — see "tvOS target" below)
yarn android-tv   # Android TV: launch your Android TV emulator/device first, then run
yarn ios          # iPhone/iPad simulator
yarn android      # phone/tablet
```

`yarn android-tv` runs the same dual-purpose APK as `yarn android` — Android TV launches it via the `LEANBACK_LAUNCHER` intent in [`AndroidManifest.xml`](android/app/src/main/AndroidManifest.xml), so there is no separate TV build target. Connect/start your Android TV emulator first; the app installs and launches on it.

## Quality gates

```bash
yarn typecheck    # tsc --noEmit
yarn test         # Jest (React Native preset)
```

There is no `lint` script: `typescript-eslint` is not yet compatible with TypeScript 7 (the project's TS version), so ESLint was removed rather than pinning TS down. `yarn typecheck` is the type-quality gate. Re-add ESLint once `typescript-eslint` supports TS 7.

Path aliases (`@/`, `@baander/shared`) are defined once in [`tsconfig.json`](tsconfig.json) and derived for Babel, Metro, and Jest by [`scripts/paths.js`](scripts/paths.js) — edit aliases there only.

## Reset / clean caches

Two-platform native development is cache-heavy. When builds misbehave:

```bash
yarn clean                 # clears Metro tmp, Watchman, Gradle, and ios/android build dirs
yarn pods                  # re-runs `pod install` (macOS)
yarn start --reset-cache   # Metro cache reset only
```

## Creating the tvOS target (macOS)

The tvOS **native build target does not exist yet** — the Xcode project currently builds iPhone/iPad only, and `ios/Baander-tvOS/` holds starter source files ([`AppDelegate.swift`](ios/Baander-tvOS/AppDelegate.swift), [`Info.plist`](ios/Baander-tvOS/Info.plist), [`LaunchScreen.storyboard`](ios/Baander-tvOS/LaunchScreen.storyboard)) authored from Linux but **not yet wired or verified**. Complete it on macOS:

1. In Xcode, add a new **tvOS App** target named `Baander-tvOS` (`SDKROOT = appletvos`, `SUPPORTED_PLATFORMS = "appletvos appletvossimulator"`, `TARGETED_DEVICE_FAMILY = 3`). Use the starter `AppDelegate.swift` / `Info.plist` / `LaunchScreen.storyboard` from `ios/Baander-tvOS/`.
2. Mark the `Baander-tvOS` scheme **shared** (so `react-native run-ios --scheme Baander-tvOS` resolves it).
3. Add a tvOS target block to [`ios/Podfile`](ios/Podfile):
   ```ruby
   target 'Baander-tvOS' do
     config = use_native_modules!
     use_react_native!(
       :path => config[:reactNativePath],
       :app_path => "#{Pod::Config.instance.installation_root}/.."
     )
   end
   ```
   then `yarn pods`.
4. Verify: `yarn tvos` builds and launches on the Apple TV simulator.

Until this is done, `yarn tvos` fails (no `Baander-tvOS` scheme).

## Notes

- **TypeScript 7** is in use ahead of some tooling; ESLint is intentionally absent (see Quality gates).
- The TV feature layer (`src/features/tv`) is deep and shared across tvOS + Android TV; it is independent of the native target work above.
