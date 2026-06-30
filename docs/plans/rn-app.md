# Plan: React Native App (ui/rn)

## Problem summary

Baander needs native apps for iOS, Android, macOS, Windows, Apple TV, and Android TV. Electron covers Linux and remains unchanged. The RN app must deliver full feature parity with the existing web app (`ui/web`) using the same three-panel design language, DPoP auth flow, and Zustand state patterns.

Requirements: `docs/brainstorms/rn-app.md`

## Relevant learnings

No prior solutions in `docs/solutions/` for RN or shared package extraction. This is greenfield.

**Key decision:** Custom native audio module instead of react-native-track-player. Third-party RN libraries get abandoned too quickly to depend on for core functionality. Own the native layer per platform.

**Version pin:** `react-native-tvos` 0.81.5-2 replaces vanilla `react-native`. It's a drop-in fork that adds Apple TV + Android TV support at no cost. `react-native-macos` (0.81.7) and `react-native-windows` (0.81.20) may flag peerDep warnings against the tvos package -- use `--force` or patch if needed. The TVOS fork only adds TV-specific native code; it doesn't alter mobile/desktop behavior.

## Scope boundaries

### In scope
- `react-native-tvos` 0.81.5-2 project scaffold at `ui/rn/` (drop-in RN fork with Apple TV + Android TV support)
- Shared API client package extraction to `ui/shared/`
- Platform-aware DPoP crypto (react-native-quick-crypto)
- Three separate UI layers: desktop (three-panel AppShell), mobile (dedicated mobile UI), TV (dedicated 10-foot UI)
- All Zustand stores ported to RN
- All UI components ported to RN (NativeWind / StyleSheet)
- **Custom native audio module** (no react-native-track-player)
- Auth flow (login, register, token refresh, DPoP)
- All feature pages (music, movies, TV, podcasts, concerts, ebooks, admin, settings, equalizer, radio, playlists)
- Server discovery (mDNS)
- Deep linking (`baander://`)
- Image loading with blurhash
- Keyboard shortcuts (desktop)
- Media session (lock screen / notification controls)
- **TV navigation** (D-pad focus, voice search via Siri/Assistant on TV remotes)

### Version Pin

| Package | Version | Reason |
|---------|---------|--------|
| `react-native-tvos` | 0.81.5-2 | Drop-in fork of RN with Apple TV + Android TV support |
| `react-native-macos` | 0.81.7 | Latest macOS stable |
| `react-native-windows` | 0.81.20 | 0.81-stable tag |

### Out of scope (v1)
- Equalizer DSP (no Web Audio API in RN -- disabled with message)
- Gapless playback / crossfade (follow-up after custom audio module stabilizes)
- Offline mode / cached metadata
- OTA updates
- CI/CD pipeline for app store builds
- Automated E2E tests (manual verification per platform)
- Windows-specific native module gaps (accept fallbacks)

---

## Implementation units

### Unit 1: Shared API Package Extraction

**Goal:** Extract crypto and API client from `ui/web/src/shared/` into `ui/shared/` with platform-aware crypto backend. Web and electron continue working via re-exports.

**Files:**
- Create: `ui/shared/package.json`, `ui/shared/tsconfig.json`
- Create: `ui/shared/crypto/platform.ts` (crypto backend interface)
- Create: `ui/shared/crypto/platform-web.ts` (Web Crypto implementation)
- Create: `ui/shared/crypto/dpop-proof.ts` (moved from web)
- Create: `ui/shared/crypto/dpop-key-pair.ts` (moved from web)
- Create: `ui/shared/crypto/dpop-store.ts` (moved from web)
- Create: `ui/shared/crypto/auth-db.ts` (moved from web)
- Create: `ui/shared/api-client/axios-instance.ts` (moved from web)
- Create: `ui/shared/api-client/gen/` (re-export from orval output)
- Create: `ui/shared/types/` (shared Track interface etc.)
- Modify: `ui/web/src/shared/crypto/dpop-proof.ts` → re-export from `ui/shared`
- Modify: `ui/web/src/shared/crypto/dpop-key-pair.ts` → re-export from `ui/shared`
- Modify: `ui/web/src/shared/crypto/dpop-store.ts` → re-export from `ui/shared`
- Modify: `ui/web/src/shared/crypto/auth-db.ts` → re-export from `ui/shared`
- Modify: `ui/web/src/shared/api-client/axios-instance.ts` → re-export from `ui/shared`
- Test: `ui/shared/crypto/__tests__/dpop-proof.test.ts` (test vectors shared between web and RN)

**Patterns to follow:**
- Existing `ui/web/src/shared/crypto/dpop-proof.ts` for DPoP proof structure
- Existing `ui/web/src/shared/api-client/axios-instance.ts` for interceptor pattern
- Platform detection via `typeof window !== 'undefined'` or explicit import

**Test scenarios:**
- DPoP proof generation produces valid ES256 JWT (shared test vector)
- Axios instance attaches DPoP header to requests
- 401 response triggers token refresh with new DPoP proof
- `use_dpop_nonce` 400 error triggers retry with nonce
- Web app still works after re-export migration (smoke test)

**Verification:**
```bash
cd ui/web && yarn test
cd ui/shared && yarn test
```

**Dependencies:** None (foundation unit)

---

### Unit 2: RN Project Scaffold

**Goal:** Initialize bare React Native project at `ui/rn/` with TypeScript, ESM, NativeWind, React Navigation, Zustand, and metro config. App launches on iOS and Android with a blank screen.

**Files:**
- Create: `ui/rn/` via `npx react-native init`
- Create: `ui/rn/package.json` (dependencies: react-native-quick-crypto, react-native-keychain, @react-navigation/native, @react-navigation/drawer, @react-navigation/stack, @react-navigation/bottom-tabs, nativewind, zustand, @react-native-async-storage/async-storage, react-native-fast-image, react-native-blurhash, react-native-safe-area-context, react-native-screens, react-native-gesture-handler, react-native-reanimated, axios)
- Create: `ui/rn/tsconfig.json`
- Create: `ui/rn/metro.config.js`
- Create: `ui/rn/babel.config.js` (NativeWind + reanimated plugins)
- Create: `ui/rn/src/app/App.tsx` (root with NavigationContainer, routes to Desktop/Mobile/TV shell based on Platform)
- Create: `ui/rn/src/shared/theme/tokens.ts` (design tokens as RN constants)
- Create: `ui/rn/src/shared/theme/colors.ts`
- Create: `ui/rn/src/shared/theme/typography.ts`
- Modify: root `package.json` or workspace config to include `ui/rn` and `ui/shared`

**Patterns to follow:**
- `ui/web/package.json` for workspace structure
- `ui/DESIGN.md` for token values

**Test scenarios:**
- `yarn ios` launches app in iOS simulator
- `yarn android` launches app in Android emulator
- NativeWind classes render correctly on a test component
- Zustand store persists to AsyncStorage and hydrates on reload

**Verification:**
```bash
cd ui/rn && yarn ios
cd ui/rn && yarn android
```

**Dependencies:** Unit 1 (for `ui/shared` import)

---

### Unit 3: Auth Flow (RN)

**Goal:** Login, register, token refresh, DPoP proof generation, and credential storage on RN. Uses shared crypto from `ui/shared/` with `react-native-quick-crypto` backend.

**Files:**
- Create: `ui/rn/src/shared/crypto/platform-rn.ts` (quick-crypto backend)
- Create: `ui/rn/src/shared/crypto/keychain-storage.ts` (secure key storage via react-native-keychain)
- Create: `ui/rn/src/features/auth/stores/auth-store.ts` (Zustand persist with AsyncStorage)
- Create: `ui/rn/src/features/auth/pages/LoginPage.tsx`
- Create: `ui/rn/src/features/auth/pages/RegisterPage.tsx`
- Create: `ui/rn/src/features/auth/components/ProtectedRoute.tsx`
- Create: `ui/rn/src/app/navigation/auth-stack.tsx`
- Test: `ui/rn/src/features/auth/__tests__/auth-store.test.ts`
- Test: `ui/rn/src/shared/crypto/__tests__/platform-rn.test.ts`

**Patterns to follow:**
- `ui/web/src/features/auth/stores/auth-store.ts` for store structure
- `ui/web/src/shared/crypto/dpop-key-pair.ts` for key generation (uses platform backend)
- `ui/shared/crypto/platform-web.ts` as reference for platform-rn.ts

**Test scenarios:**
- Auth store persists tokens to AsyncStorage
- DPoP key pair generates via react-native-quick-crypto
- Login API call includes valid DPoP proof header
- Token refresh works with DPoP proof
- Logout clears tokens and key pair
- App redirects to login when not authenticated

**Verification:**
```bash
cd ui/rn && yarn test src/features/auth
```
Manual: login flow completes against running Baander server.

**Dependencies:** Unit 1, Unit 2

---

### Unit 4: Desktop AppShell + Navigation (macOS/Windows)

**Goal:** Three-panel desktop shell matching the web app. Persistent sidebar, resizable context panel, keyboard shortcuts. Desktop-only components.

**Files:**
- Create: `ui/rn/src/features/desktop/components/DesktopAppShell.tsx`
- Create: `ui/rn/src/features/desktop/components/Sidebar.tsx`
- Create: `ui/rn/src/features/desktop/components/SidebarSelector.tsx`
- Create: `ui/rn/src/features/desktop/components/SidebarContent.tsx`
- Create: `ui/rn/src/features/desktop/components/SidebarPinnedFooter.tsx`
- Create: `ui/rn/src/features/desktop/components/ContextPanel.tsx`
- Create: `ui/rn/src/features/desktop/components/DesktopNowPlayingBar.tsx`
- Create: `ui/rn/src/features/desktop/components/SidebarEditor.tsx`
- Create: `ui/rn/src/features/desktop/stores/sidebar-store.ts`
- Create: `ui/rn/src/features/desktop/stores/context-panel-store.ts`
- Create: `ui/rn/src/features/desktop/stores/media-mode-store.ts`
- Create: `ui/rn/src/shared/components/ui/button.tsx`
- Create: `ui/rn/src/shared/components/ui/input.tsx`
- Create: `ui/rn/src/shared/components/ui/separator.tsx`
- Create: `ui/rn/src/shared/components/ui/skeleton.tsx`
- Create: `ui/rn/src/shared/components/ui/tabs.tsx`
- Create: `ui/rn/src/shared/components/ui/sheet.tsx`
- Create: `ui/rn/src/shared/components/ui/slider.tsx`
- Create: `ui/rn/src/shared/components/ui/badge.tsx`
- Create: `ui/rn/src/shared/components/ui/toggle.tsx`
- Create: `ui/rn/src/shared/components/ui/switch.tsx`
- Create: `ui/rn/src/shared/components/ui/scroll-area.tsx`
- Create: `ui/rn/src/shared/components/ui/dialog.tsx`
- Create: `ui/rn/src/shared/components/ui/context-menu.tsx`
- Create: `ui/rn/src/shared/components/ui/tooltip.tsx`
- Create: `ui/rn/src/app/navigation/desktop-navigator.tsx`
- Test: `ui/rn/src/features/desktop/__tests__/DesktopAppShell.test.tsx`

**Patterns to follow:**
- `ui/web/src/features/layout/components/AppShell.tsx` for structure
- `ui/web/src/features/layout/components/Sidebar.tsx` for sidebar layout
- `ui/web/src/features/layout/components/ContextPanel.tsx` for panel structure
- `ui/DESIGN.md` for spacing, colors, border radius

**Test scenarios:**
- DesktopAppShell renders sidebar, content area, and context panel in flex row
- Sidebar shows media selector, navigation items, settings
- Context panel toggles between compact/expanded modes
- DesktopNowPlayingBar renders at bottom
- Sidebar collapsed/expanded state persists

**Verification:**
```bash
cd ui/rn && yarn test src/features/desktop
```

**Dependencies:** Unit 2, Unit 3 (auth gate)

---

### Unit 4b: Mobile AppShell + Navigation (iOS + Android)

**Goal:** Dedicated mobile UI with bottom tab navigation, mini-player, full-screen now-playing. Touch-first. No sidebar, no context panel -- purpose-built for phones.

**Files:**
- Create: `ui/rn/src/features/player/stores/player-store.ts` (minimal Zustand store: queue, currentTrack, isPlaying, volume, shuffle, repeat -- no AudioModule wiring yet)
- Create: `ui/rn/src/features/mobile/components/MobileAppShell.tsx`
- Create: `ui/rn/src/features/mobile/components/MobileTabBar.tsx`
- Create: `ui/rn/src/features/mobile/components/MiniPlayer.tsx`
- Create: `ui/rn/src/features/mobile/components/MobileNowPlaying.tsx` (full-screen)
- Create: `ui/rn/src/features/mobile/components/MediaTypeSelector.tsx` (segmented control)
- Create: `ui/rn/src/features/mobile/components/SwipeableTrackRow.tsx` (swipe actions)
- Create: `ui/rn/src/features/mobile/pages/MobileHomePage.tsx`
- Create: `ui/rn/src/features/mobile/pages/MobileAlbumDetailPage.tsx` (cover art header + tracks)
- Create: `ui/rn/src/features/mobile/pages/MobileArtistDetailPage.tsx` (hero image + sections)
- Create: `ui/rn/src/features/mobile/pages/MobileSearchPage.tsx` (full-screen search)
- Create: `ui/rn/src/features/mobile/pages/MobileQueuePage.tsx`
- Create: `ui/rn/src/features/mobile/pages/MobileLibraryPage.tsx`
- Create: `ui/rn/src/features/mobile/pages/MobileSettingsPage.tsx`
- Create: `ui/rn/src/app/navigation/mobile-navigator.tsx` (bottom tabs + stack)
- Test: `ui/rn/src/features/mobile/__tests__/MobileAppShell.test.tsx`

**Mobile UI layout:**
```
+----------------------------------+
|  [Music] [Movies] [TV] [More]    |  <- Segmented control
+----------------------------------+
|                                  |
|  Featured / Recent Albums       |
|  [Album] [Album] [Album]        |
|                                  |
|  Recently Played                |
|  [Track row]                    |
|  [Track row]                    |
|  [Track row]                    |
|                                  |
+----------------------------------+
|  Mini-player: Title - Artist    |  <- Tappable, expands to full
+----------------------------------+
|  [Home] [Search] [Library] [Set]|  <- Bottom tabs
+----------------------------------+
```

**Mobile Now-Playing (expanded from mini-player):**
```
+----------------------------------+
|  [v] Dismiss                     |
|                                  |
|  +--------------------------+    |
|  |                          |    |
|  |      Album Artwork       |    |
|  |       (large)            |    |
|  |                          |    |
|  +--------------------------+    |
|                                  |
|  Song Title                      |
|  Artist Name                     |
|                                  |
|  0:42 ======-------- 3:18        |
|                                  |
|  [Shuffle] [Prev] [Play] [Next] [Repeat] |
|                                  |
|  [Queue]  [Lyrics]  [AirPlay]    |
+----------------------------------+
```

**Patterns to follow:**
- Spotify/Apple Music mobile app UX patterns
- Bottom tabs: Home, Search, Library, Settings
- Mini-player: persistent above tab bar, tap to expand
- Cover art header: large image with gradient fade into track list
- Swipeable rows: left swipe reveals "Add to Queue", "Add to Playlist"
- Pull-to-refresh on browse pages

**Test scenarios:**
- MobileAppShell renders tab bar + content area
- MiniPlayer shows current track above tab bar
- Tap mini-player expands to full-screen now-playing
- Bottom tabs navigate between Home, Search, Library, Settings
- Segmented control switches media types
- Album detail shows cover art header + track list
- SwipeableTrackRow reveals actions on swipe

**Verification:**
```bash
cd ui/rn && yarn test src/features/mobile
```
Manual: navigate full app on iOS simulator + Android emulator.

**Dependencies:** Unit 2, Unit 3 (auth)

Note: MiniPlayer reads from player-store, which is created in this unit as a minimal Zustand store (queue, currentTrack, isPlaying, volume). Unit 5b later connects this store to the native AudioModule. The store shape is defined here; the AudioModule wiring happens in 5b.

---

### Unit 5: Custom Native Audio Module

**Goal:** Build a custom native audio module (`baander-audio`) with per-platform implementations. No dependency on react-native-track-player. The module exposes a unified JS API: play, pause, seek, setVolume, event callbacks (onProgress, onTrackEnd, onError). Each platform wraps its native audio stack.

**Platform implementations:**
- **iOS/macOS:** `AVPlayer` (Swift) -- handles streaming, background audio via `AVAudioSession`, `MPNowPlayingInfoCenter` for lock screen, `MPRemoteCommandCenter` for transport controls.
- **Android:** `ExoPlayer` / `MediaPlayer` (Kotlin) -- handles streaming, `MediaSession` for notification/lock screen, `MediaBrowserServiceCompat` for background service.
- **Windows:** `MediaPlayer` from `Windows.Media.Playback` (C++/WinRT) -- handles streaming, `SMTC` (System Media Transport Controls) for lock screen/taskbar.

**Files:**
- Create: `ui/rn/src/native/audio/AudioModule.ts` (TS API: play/pause/seek/setVolume + NativeEventEmitter)
- Create: `ui/rn/src/native/audio/types.ts` (AudioTrack, PlaybackState, AudioEvent)
- Create: `ui/rn/src/native/audio/NativeAudio.ts` (Turbo Native Module spec via Codegen)
- Create: `ui/rn/ios/BaanderAudio/BaanderAudioManager.swift` (AVPlayer wrapper)
- Create: `ui/rn/ios/BaanderAudio/BaanderAudioManager-Bridging-Header.h`
- Create: `ui/rn/macos/BaanderAudio/BaanderAudioManager.swift` (shared with iOS via CocoaPods)
- Create: `ui/rn/android/app/src/main/java/com/baander/audio/BaanderAudioModule.kt`
- Create: `ui/rn/android/app/src/main/java/com/baander/audio/BaanderAudioPackage.kt`
- Create: `ui/rn/android/app/src/main/java/com/baander/audio/AudioService.kt` (foreground service)
- Create: `ui/rn/android/app/src/main/AndroidManifest.xml` (service declaration)
- Create: `ui/rn/windows/BaanderAudio/BaanderAudioManager.h` (C++/WinRT)
- Create: `ui/rn/windows/BaanderAudio/BaanderAudioManager.cpp`
- Test: `ui/rn/src/native/audio/__tests__/AudioModule.test.ts`

**Unified JS API:**
```typescript
interface AudioModule {
  play(url: string, headers?: Record<string, string>): void
  pause(): void
  seekTo(seconds: number): void
  setVolume(level: number): void  // 0.0 - 1.0
  stop(): void
  // Events via NativeEventEmitter
  onProgress: (callback: (position: number, duration: number) => void) => () => void
  onTrackEnd: (callback: () => void) => () => void
  onError: (callback: (error: string) => void) => () => void
  onStateChange: (callback: (state: 'playing' | 'paused' | 'stopped' | 'error') => void) => () => void
}
```

**Patterns to follow:**
- RN Turbo Native Module pattern (new arch, RN 0.81 supports it)
- `react-native` Codegen for type-safe native module bindings
- Stream URL: `/api/stream/track?id={publicId}` with DPoP Authorization header

**Test scenarios:**
- Native module loads on each platform without crash
- `play(url, headers)` starts playback
- `pause()` / `seekTo()` work correctly
- `onProgress` fires at regular intervals during playback
- `onTrackEnd` fires when a track finishes
- Background audio continues on iOS/Android when app is backgrounded
- Lock screen shows track metadata (title, artist, album art)
- Lock screen transport controls (play/pause/next/prev) work
- Volume control works
- Error handling: network failure, invalid URL, codec not supported

**Verification:**
```bash
cd ui/rn && yarn test src/native/audio
```
Manual: play a track from Baander server, background the app, verify audio continues, verify lock screen controls.

**Dependencies:** Unit 2 (project scaffold), Unit 3 (auth headers for stream URLs)

**Full technical spec:** `docs/plans/rn-app-audio-module.md` -- contains per-platform native code for AVPlayer (iOS/macOS), ExoPlayer (Android), MediaPlayer (Windows), Turbo Native Module spec, DPoP header injection, and testing strategy.

---

### Unit 5b: Player Store + Playback Integration

**Goal:** Wire the player-store (created in Unit 4b) to the custom AudioModule. Add queue management, shuffle, repeat, volume, and track-end handling. Player UI components shared across all UI layers.

**Files:**
- Modify: `ui/rn/src/features/player/stores/player-store.ts` (add AudioModule wiring, queue logic, shuffle/repeat)
- Create: `ui/rn/src/features/player/stores/player-handlers.ts`
- Create: `ui/rn/src/features/player/hooks/use-audio-playback.ts`
- Create: `ui/rn/src/features/player/components/ProgressBar.tsx`
- Create: `ui/rn/src/features/player/components/NowPlayingCompact.tsx`
- Create: `ui/rn/src/features/player/components/NowPlayingFull.tsx`
- Create: `ui/rn/src/features/player/components/QueueTab.tsx`
- Create: `ui/rn/src/features/player/components/PlayerBar.tsx`
- Test: `ui/rn/src/features/player/__tests__/player-store.test.ts`

**Patterns to follow:**
- `ui/web/src/features/player/stores/player-store.ts` for store interface (Track, RepeatMode, actions)
- Store calls `AudioModule.play()` instead of `audioElement.src = ...`
- Same queue/repeat/shuffle logic as web

**Test scenarios:**
- Player store initializes with empty queue
- `playTrack(track, queue)` sets current track and calls AudioModule.play()
- `playNext` / `playPrevious` advance through queue
- Shuffle randomizes next track selection
- Repeat modes cycle off -> all -> one
- Volume and mute state persist
- `onTrackEnd` from AudioModule triggers `playNext`

**Verification:**
```bash
cd ui/rn && yarn test src/features/player
```
Manual: play a track, background audio continues, lock screen shows controls.

**Dependencies:** Unit 4 (desktop shell), Unit 4b (mobile shell + player-store shape), Unit 5 (native audio module)

---

### Unit 6: Catalog -- Shared Components + Pages for All UIs

**Goal:** Shared catalog components (data-fetching hooks, list items, cards, stores) and UI-specific pages for desktop and mobile. Catalog pages live under `features/desktop/pages/` and `features/mobile/pages/`, composing shared components from `features/catalog/`.

**Shared catalog components (UI-agnostic):**
- Create: `ui/rn/src/features/catalog/stores/selection-store.ts`
- Create: `ui/rn/src/features/catalog/stores/view-mode-store.ts`
- Create: `ui/rn/src/features/catalog/stores/list-column-store.ts`
- Create: `ui/rn/src/features/catalog/hooks/use-albums.ts`
- Create: `ui/rn/src/features/catalog/hooks/use-artists.ts`
- Create: `ui/rn/src/features/catalog/hooks/use-tracks.ts`
- Create: `ui/rn/src/features/catalog/hooks/use-genres.ts`
- Create: `ui/rn/src/features/catalog/components/AlbumCard.tsx` (shared card, renders differently per Platform)
- Create: `ui/rn/src/features/catalog/components/ArtistCard.tsx`
- Create: `ui/rn/src/features/catalog/components/TrackRow.tsx` (shared row, swipeable on mobile)
- Create: `ui/rn/src/features/catalog/components/GenreChip.tsx`

**Desktop catalog pages:**
- Create: `ui/rn/src/features/desktop/pages/DesktopHomePage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopAlbumsPage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopAlbumDetailPage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopArtistsPage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopArtistDetailPage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopSongsPage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopGenresPage.tsx`
- Create: `ui/rn/src/features/desktop/pages/DesktopSearchPage.tsx`

**Mobile catalog pages (already started in Unit 4b, extended here):**
- Modify: `ui/rn/src/features/mobile/pages/MobileHomePage.tsx` (add real data fetching)
- Create: `ui/rn/src/features/mobile/pages/MobileAlbumsPage.tsx`
- Create: `ui/rn/src/features/mobile/pages/MobileSongsPage.tsx`
- Create: `ui/rn/src/features/mobile/pages/MobileGenresPage.tsx`

**Shared UI components:**
- Create: `ui/rn/src/shared/components/dashboard-section.tsx`
- Create: `ui/rn/src/shared/components/horizontal-scroll-row.tsx`
- Create: `ui/rn/src/shared/components/LoadingSkeleton.tsx`
- Create: `ui/rn/src/shared/components/media-type-home-page.tsx`
- Create: `ui/rn/src/shared/components/error-banner.tsx`
- Create: `ui/rn/src/shared/components/ErrorBoundary.tsx`
- Modify: `ui/rn/src/shared/components/ui/context-menu.tsx` (created in Unit 4, extend for catalog)
- Create: `ui/rn/src/shared/components/ui/filter-bar.tsx`
- Create: `ui/rn/src/shared/components/ui/select.tsx`
- Create: `ui/rn/src/shared/hooks/use-image-blob.ts` (adapted for RN FastImage)
- Create: `ui/rn/src/shared/utils/blurhash.ts`
- Create: `ui/rn/src/shared/utils/format-duration.ts`
- Create: `ui/rn/src/shared/utils/format-relative-time.ts`
- Test: `ui/rn/src/features/catalog/__tests__/AlbumCard.test.tsx`
- Test: `ui/rn/src/features/desktop/__tests__/DesktopAlbumsPage.test.tsx`

**Patterns to follow:**
- `ui/web/src/features/catalog/pages/` for page structure
- `ui/web/src/shared/components/dashboard-section.tsx` for home page sections
- `ui/web/src/shared/components/ui/filter-bar.tsx` for filter UI
- `ui/DESIGN.md` for card layout, grid spacing, view modes

**Test scenarios:**
- Home page renders dashboard sections
- Albums page renders grid of album cards
- Album detail page renders track list
- Search page queries API and displays results
- Selection store tracks multi-select state
- View mode store toggles grid/list/columns

**Verification:**
```bash
cd ui/rn && yarn test src/features/catalog
```
Manual: browse music library, open album detail, search for tracks.

**Dependencies:** Unit 4 (desktop navigation), Unit 4b (mobile navigation), Unit 5b (play from catalog)

---

### Unit 7: Playlists + Radio + Equalizer

**Goal:** Playlist management, radio playback, and equalizer page (EQ disabled on RN with message, desktop may support via native module later).

**Files:**
- Create: `ui/rn/src/features/playlist/pages/PlaylistsPage.tsx`
- Create: `ui/rn/src/features/radio/pages/RadioPage.tsx`
- Create: `ui/rn/src/features/radio/stores/radio-store.ts`
- Create: `ui/rn/src/features/radio/hooks/use-radio-audio.ts`
- Create: `ui/rn/src/features/equalizer/pages/EqualizerPage.tsx`
- Create: `ui/rn/src/features/equalizer/stores/eq-bands-store.ts`
- Create: `ui/rn/src/features/equalizer/stores/eq-compare-store.ts`
- Create: `ui/rn/src/features/equalizer/stores/eq-processing-store.ts`
- Create: `ui/rn/src/features/equalizer/stores/eq-profiles-store.ts`
- Test: `ui/rn/src/features/playlist/__tests__/PlaylistsPage.test.tsx`

**Patterns to follow:**
- `ui/web/src/features/playlist/` for playlist page structure
- `ui/web/src/features/radio/` for radio store and hooks
- `ui/web/src/features/equalizer/` for EQ store structure

**Test scenarios:**
- Playlists page renders user playlists
- Create/edit/delete playlist flows
- Radio page plays stream
- Equalizer page renders bands UI (no DSP on RN)
- EQ stores persist band values

**Verification:**
```bash
cd ui/rn && yarn test src/features/playlist src/features/radio src/features/equalizer
```

**Dependencies:** Unit 5b (player for adding songs to queue)

---

### Unit 8: Settings + Admin

**Goal:** Settings page (server config, playback prefs, display) and full admin panel (dashboard, jobs, rate limiters, diagnostics, config, users, scanning, activity, recommendations).

**Files:**
- Create: `ui/rn/src/features/settings/pages/SettingsPage.tsx`
- Create: `ui/rn/src/features/settings/hooks/use-player-preferences.ts`
- Create: `ui/rn/src/features/admin/components/AdminShell.tsx`
- Create: `ui/rn/src/features/admin/components/AdminRoute.tsx`
- Create: `ui/rn/src/features/admin/pages/AdminDashboardPage.tsx`
- Create: `ui/rn/src/features/admin/pages/JobMonitorPage.tsx`
- Create: `ui/rn/src/features/admin/pages/RateLimitersPage.tsx`
- Create: `ui/rn/src/features/admin/pages/ServerDiagnosticsPage.tsx`
- Create: `ui/rn/src/features/admin/pages/ConfigurationPage.tsx`
- Create: `ui/rn/src/features/admin/pages/UsersPage.tsx`
- Create: `ui/rn/src/features/admin/pages/ScanningPage.tsx`
- Create: `ui/rn/src/features/admin/pages/ActivityPage.tsx`
- Create: `ui/rn/src/features/admin/pages/RecommendationsPage.tsx`
- Create: `ui/rn/src/features/admin/pages/AdminSettingsPage.tsx`
- Create: `ui/rn/src/app/navigation/admin-stack.tsx`
- Create: `ui/rn/src/shared/components/ui/table.tsx`
- Create: `ui/rn/src/shared/components/ui/textarea.tsx`
- Test: `ui/rn/src/features/settings/__tests__/SettingsPage.test.tsx`

**Patterns to follow:**
- `ui/web/src/features/settings/pages/SettingsPage.tsx`
- `ui/web/src/features/admin/pages/` for all admin pages
- `ui/web/src/shared/components/ui/table.tsx` for admin tables

**Test scenarios:**
- Settings page renders server URL config
- Admin dashboard renders stats
- Job monitor page lists running jobs
- Users page lists and manages users
- Admin route guard redirects non-admin users

**Verification:**
```bash
cd ui/rn && yarn test src/features/settings src/features/admin
```

**Dependencies:** Unit 4 (desktop navigation), Unit 4b (mobile settings page), Unit 3 (auth for admin role check)

---

### Unit 9: Other Media Pages

**Goal:** Movies, TV, Podcasts, Concerts, E-books home pages. Each follows the same `MediaTypeHomePage` pattern.

**Files:**
- Create: `ui/rn/src/features/movies/pages/MoviesHomePage.tsx`
- Create: `ui/rn/src/features/tv-shows/pages/TVShowsHomePage.tsx`
- Create: `ui/rn/src/features/podcasts/pages/PodcastsHomePage.tsx`
- Create: `ui/rn/src/features/concerts/pages/ConcertsHomePage.tsx`
- Create: `ui/rn/src/features/ebooks/pages/EbooksHomePage.tsx`
- Test: `ui/rn/src/features/movies/__tests__/MoviesHomePage.test.tsx`

**Patterns to follow:**
- `ui/web/src/shared/components/media-type-home-page.tsx` for shared layout
- `ui/web/src/features/movies/pages/MoviesHomePage.tsx`

**Test scenarios:**
- Each media page renders home content
- Media type selector in sidebar switches between media types
- Navigation routes match web app structure

**Verification:**
```bash
cd ui/rn && yarn test src/features/movies src/features/tv-shows src/features/podcasts src/features/concerts src/features/ebooks
```

**Dependencies:** Unit 6 (catalog patterns)

---

### Unit 10: Notifications + Lyrics + Details Tabs

**Goal:** Notification bell with SSE/WebSocket, lyrics tab in context panel, track details tab, lyrics fullscreen overlay.

**Files:**
- Create: `ui/rn/src/features/notification/components/NotificationBell.tsx`
- Create: `ui/rn/src/features/notification/components/NotificationPopout.tsx`
- Create: `ui/rn/src/features/notification/hooks/use-notifications.ts`
- Create: `ui/rn/src/features/notification/stores/notification-store.ts`
- Create: `ui/rn/src/features/player/components/LyricsTab.tsx`
- Create: `ui/rn/src/features/player/components/DetailsTab.tsx`
- Create: `ui/rn/src/features/desktop/components/LyricsFullscreenOverlay.tsx`
- Create: `ui/rn/src/features/desktop/stores/lyrics-fullscreen-store.ts`
- Create: `ui/rn/src/shared/components/ui/sonner.tsx` (toast replacement)
- Test: `ui/rn/src/features/notification/__tests__/notification-store.test.ts`

**Patterns to follow:**
- `ui/web/src/features/notification/` for notification components
- `ui/web/src/features/player/components/LyricsTab.tsx`
- `ui/web/src/features/player/components/DetailsTab.tsx`
- `ui/web/src/features/layout/components/LyricsFullscreenOverlay.tsx`

**Test scenarios:**
- Notification store receives and displays notifications
- Lyrics tab displays synced lyrics for current track
- Details tab shows track metadata
- Lyrics fullscreen overlay expands/collapses

**Verification:**
```bash
cd ui/rn && yarn test src/features/notification src/features/player
```

**Dependencies:** Unit 5b (player for current track), Unit 4 (desktop context panel tabs), Unit 4b (mobile now-playing)

---

### Unit 11: Server Discovery + Deep Linking

**Goal:** mDNS server discovery (matching Electron), deep link handling (`baander://`), and initial server config screen.

**Files:**
- Create: `ui/rn/src/features/settings/services/discovery-service.ts`
- Create: `ui/rn/src/features/settings/services/deep-link-service.ts`
- Create: `ui/rn/src/features/settings/pages/ServerConfigScreen.tsx` (first-launch)
- Modify: `ui/rn/ios/.../Info.plist` (URL scheme, Bonjour declarations)
- Modify: `ui/rn/android/.../AndroidManifest.xml` (intent filters)
- Test: `ui/rn/src/features/settings/__tests__/discovery-service.test.ts`

**Patterns to follow:**
- `ui/electron/src/main/services/discovery.service.ts` for mDNS pattern
- `ui/electron/src/main/services/deep-link.service.ts` for URL handling

**Test scenarios:**
- Discovery finds Baander servers on local network
- Deep link `baander://album/xyz` navigates to album detail
- First-launch screen shows discovered servers
- Server URL persists across app restarts

**Verification:**
```bash
cd ui/rn && yarn test src/features/settings
```
Manual: open `baander://` link from another app, verify navigation.

**Dependencies:** Unit 3 (auth), Unit 4 (desktop navigation), Unit 4b (mobile navigation)

---

### Unit 12: Keyboard Shortcuts (Desktop) + Menu Bar

**Goal:** Keyboard shortcut support on macOS/Windows matching the Electron app's accelerator definitions. Native menu bar integration.

**Files:**
- Create: `ui/rn/src/shared/hooks/use-keyboard-shortcuts.ts`
- Create: `ui/rn/src/features/desktop/hooks/use-media-shortcuts.ts`
- Create: `ui/rn/src/shared/components/keyboard-shortcuts-help.tsx`
- Create: `ui/rn/src/features/desktop/components/SpotlightOverlay.tsx` (desktop only)
- Create: native menu module (macOS/Windows) if needed
- Test: `ui/rn/src/shared/hooks/__tests__/use-keyboard-shortcuts.test.ts`

**Patterns to follow:**
- `ui/electron/src/main/menu/accelerators.ts` for shortcut definitions
- `ui/electron/src/main/menu/sections/` for menu structure
- `ui/web/src/shared/hooks/use-keyboard-shortcuts.ts` for hook pattern

**Test scenarios:**
- Space toggles play/pause
- Arrow keys skip forward/back
- Cmd/Ctrl+K opens search
- Cmd/Ctrl+Shift+S toggles sidebar
- Shortcuts disabled on mobile platforms

**Verification:**
```bash
cd ui/rn && yarn macos  # manual keyboard testing
```

**Dependencies:** Unit 4 (desktop AppShell), Unit 5b (player controls)

---

### Unit 13: macOS + Windows Platform Targets

**Goal:** Configure `react-native-macos` and `react-native-windows` extensions. Desktop apps launch with the full three-panel layout, native window management, and menu bar.

**Files:**
- Create: `ui/rn/macos/` (via `npx react-native-macos-init`)
- Create: `ui/rn/windows/` (via `npx react-native-windows-init`)
- Create: `ui/rn/src/platforms/macos/AppDelegate.mm`
- Create: `ui/rn/src/platforms/windows/App.cpp`
- Modify: `ui/rn/src/app/App.tsx` (platform detection for desktop layout)
- Modify: `ui/rn/src/app/App.tsx` (desktop branch)

**Patterns to follow:**
- `ui/electron/src/main/windows/main-window.ts` for window sizing
- `ui/electron/src/main/menu/` for menu structure

**Test scenarios:**
- macOS app launches with three-panel layout
- Windows app launches with three-panel layout
- Sidebar is persistent (not a drawer)
- Context panel resizes via drag handle
- Window title, icon, and menu bar are correct

**Verification:**
```bash
cd ui/rn && yarn macos
cd ui/rn && yarn windows
```

**Dependencies:** Units 1-12 (all features must work on mobile before desktop validation)

---

### Unit 13b: TV App (Apple TV + Android TV) -- Dedicated UI

**Goal:** Build a dedicated TV UI for Apple TV and Android TV. Not an adaptation of the mobile/desktop three-panel layout -- a purpose-built 10-foot UI optimized for D-pad navigation. Same codebase for both TV platforms, same design language, same stores and API client.

**TV UI design principles:**
- 10-foot viewing distance: large text (24px+ body), large cards, high contrast
- D-pad navigation: left/right browses horizontal rows, up/down switches rows, enter selects
- No sidebar, no context panel, no now-playing bar -- everything is full-screen
- Hero section at top with current/featured content
- Horizontal content rows (like Netflix/Apple Music TV layout)
- Now-playing overlay on top of browse (album art + controls, D-pad dismisses)
- Same color tokens as mobile/desktop (dark theme: #000/#0a0a0b/#080809)
- Same Inter font
- Same flat, no-gradient, no-shadow design language

**Files:**
- Create: `ui/rn/tvos/` (tvOS Xcode project)
- Create: `ui/rn/src/features/tv/` (entire TV-specific UI layer)
- Create: `ui/rn/src/features/tv/components/TVAppShell.tsx` (full-screen shell, no panels)
- Create: `ui/rn/src/features/tv/components/TVHeroSection.tsx` (featured content, large artwork)
- Create: `ui/rn/src/features/tv/components/TVContentRow.tsx` (horizontal browse row)
- Create: `ui/rn/src/features/tv/components/TVCard.tsx` (large focusable card for grids)
- Create: `ui/rn/src/features/tv/components/TVFocusable.tsx` (D-pad focus wrapper)
- Create: `ui/rn/src/features/tv/components/TVNavigationBar.tsx` (top bar: logo + search + settings)
- Create: `ui/rn/src/features/tv/components/TVDetailScreen.tsx` (album/artist detail, full-screen)
- Create: `ui/rn/src/features/tv/components/TVNowPlayingOverlay.tsx` (fullscreen now-playing, overlay on browse)
- Create: `ui/rn/src/features/tv/components/TVSearchScreen.tsx` (on-screen keyboard + results)
- Create: `ui/rn/src/features/tv/components/TVLoginScreen.tsx` (on-screen keyboard for server URL + credentials)
- Create: `ui/rn/src/features/tv/navigation/TVNavigator.tsx` (stack + focus management)
- Create: `ui/rn/src/features/tv/hooks/use-tv-focus.ts` (D-pad focus management)
- Create: `ui/rn/src/features/tv/hooks/use-tv-remote.ts` (remote button handlers)
- Create: `ui/rn/src/features/tv/pages/TVHomePage.tsx` (hero + content rows)
- Create: `ui/rn/src/features/tv/pages/TVAlbumDetailPage.tsx`
- Create: `ui/rn/src/features/tv/pages/TVArtistDetailPage.tsx`
- Create: `ui/rn/src/features/tv/pages/TVCatalogPage.tsx` (browse by genre/mood)
- Create: `ui/rn/src/features/tv/pages/TVQueuePage.tsx` (queue view)
- Create: `ui/rn/src/features/tv/pages/TVSettingsPage.tsx` (server config, playback prefs)
- Create: `ui/rn/src/features/tv/theme/tv-tokens.ts` (TV-specific spacing/sizing overrides)
- Modify: `ui/rn/src/app/App.tsx` (route to TV app when `isTV()` is true)
- Modify: `ui/rn/src/features/player/stores/player-store.ts` (add TV-specific actions if needed)
- Test: `ui/rn/src/features/tv/__tests__/TVFocusable.test.tsx`
- Test: `ui/rn/src/features/tv/__tests__/TVContentRow.test.tsx`

**TV UI layout (same on Apple TV and Android TV):**

```
+----------------------------------------------------------+
|  [Logo]     [Music] [Movies] [TV] [Podcasts]    [Search]  |  <- NavigationBar (top)
+----------------------------------------------------------+
|                                                          |
|   +--------------------------------------------------+   |
|   |                                                  |   |
|   |  HERO SECTION (featured album / now playing)     |   |
|   |  Large artwork + title + artist + play button    |   |
|   |                                                  |   |
|   +--------------------------------------------------+   |
|                                                          |
|   Recently Played >                                      |  <- ContentRow
|   [Album] [Album] [Album] [Album] [Album] >              |  <- TVCard (focusable)
|                                                          |
|   New Releases >                                         |  <- ContentRow
|   [Album] [Album] [Album] [Album] [Album] >              |  <- TVCard (focusable)
|                                                          |
|   Your Playlists >                                       |  <- ContentRow
|   [Playlist] [Playlist] [Playlist] [Playlist] >          |  <- TVCard (focusable)
|                                                          |
+----------------------------------------------------------+
```

**TV Now-Playing overlay (press Play on remote while browsing):**

```
+----------------------------------------------------------+
|                                                          |
|   +----------+                                           |
|   |          |   Song Title                              |
|   |  Album   |   Artist Name                             |
|   |  Art     |   Album Name                              |
|   |          |   0:42 / 3:18     [<<] [||] [>>]          |
|   +----------+                                           |
|                                                          |
|   (Background: browse content continues behind overlay)  |
|   (Dismiss: press Back on remote)                        |
+----------------------------------------------------------+
```

**TV Detail screen (album/artist):**

```
+----------------------------------------------------------+
|  [< Back]                                                |
|                                                          |
|   +----------+                                           |
|   |          |  Album Title                               |
|   |  Album   |  Artist Name  |  2024  |  12 tracks       |
|   |  Art     |  [Play All]  [Shuffle]                    |
|   |  (large) |                                             |
|   +----------+                                           |
|                                                          |
|   1. Track One                 3:18                      |
|   2. Track Two                 4:05                      |
|   3. Track Three               2:44                      |
|   ...                                                    |
+----------------------------------------------------------+
```

**TV vs Mobile/Desktop -- shared vs separate:**

| Concern | Shared? | Notes |
|---------|---------|-------|
| Zustand stores | Yes | Same player-store, auth-store, etc. |
| API client (ui/shared/) | Yes | Same endpoints, types, DPoP auth |
| Audio module | Yes | Same native AVPlayer/ExoPlayer |
| Design tokens (colors) | Yes | Same palette |
| Design tokens (spacing) | Override | TV tokens: larger gaps, bigger touch targets |
| Typography | Override | TV: 24px+ body, 32px+ headings |
| Navigation | Separate | TV: horizontal rows + D-pad, no drawer/sidebar |
| Components | Separate | TV: TVCard, TVContentRow, TVHeroSection, TVFocusable |
| AppShell | Separate | TV: full-screen, no panels |
| Now-playing | Separate | TV: overlay on browse content |
| Search | Separate | TV: on-screen keyboard + voice |
| Settings | Separate | TV: simplified, on-screen keyboard |
| Login | Separate | TV: on-screen keyboard for credentials |

**TV navigation model:**
- D-pad (up/down/left/right/enter) is the only input
- Focus management: `TVFocusGuideView` (tvOS) / `TVEventHandler` (Android TV)
- Horizontal rows: left/right moves focus between cards in a row
- Vertical: up/down moves focus between rows
- Enter on a card: opens detail screen
- Play/Pause on remote: toggles playback
- Back: returns to previous screen or dismisses overlay
- Long-press / Info button: opens context options (add to queue, add to playlist)

**Patterns to follow:**
- `react-native-tvos` `TVFocusGuideView` and `TVEventHandler` APIs
- `isTV()` Platform constant for conditional app entry point
- Same `AudioModule` for playback (no TV-specific audio code needed)
- Netflix/Apple Music TV app as UX reference for content browsing

**Test scenarios:**
- `isTV()` routes to TV app shell instead of mobile/desktop shell
- TVFocusable receives D-pad focus with visual highlight
- D-pad left/right moves between cards in a content row
- D-pad up/down moves between content rows
- Enter on album card opens album detail
- Play/Pause on remote toggles audio playback
- Now-playing overlay appears/disappears correctly
- TV login screen works with on-screen keyboard
- Search screen returns results from API

**Verification:**
```bash
cd ui/rn && yarn tvos  # Apple TV simulator
```
Manual: navigate full app with Siri Remote / D-pad on Android TV emulator.

**Dependencies:** Units 1-12 (stores, API client, audio module, auth all shared)

---

### Unit 14: Polish + Cross-Platform Verification

**Goal:** Visual parity check across all platforms (iOS, Android, macOS, Windows, tvOS, Android TV). Fix platform-specific rendering issues. Blurhash images, loading skeletons, empty states, error states. Performance profiling.

**Files:**
- Modify: any files with platform-specific rendering issues
- Modify: `ui/rn/src/shared/components/ui/tooltip.tsx` (polish, created in Unit 4)
- Create: `ui/rn/src/shared/components/ui/dropdown-menu.tsx` (desktop)
- Modify: `ui/rn/src/features/desktop/components/SidebarEditor.tsx` (polish, created in Unit 4)

**Patterns to follow:**
- `ui/DESIGN.md` for visual specs
- `ui/ICON-GUIDE.md` for icon usage

**Test scenarios:**
- Visual parity with web app on desktop viewport
- Album art loads with blurhash placeholder on all platforms
- Loading skeletons match loaded content layout
- Error states show retry button
- Empty states show appropriate messages
- No gradients, no shadows except overlays
- All animations ease-out, <120ms

**Verification:**
Manual: side-by-side comparison of web app and RN app on each platform.

**Dependencies:** Units 1-13

---

## Verification strategy

### Per-unit
- Each unit has specific test commands listed above
- TDD flow: write failing test, implement, verify test passes, refactor

### Integration
- Full auth flow works on all platforms (login -> browse -> play -> logout)
- Stream playback works with DPoP auth headers on all platforms
- Each UI layer renders correctly: desktop three-panel, mobile tabs, TV rows

### Cross-platform smoke test
1. iOS: login, bottom tab navigation, mini-player, full-screen now-playing, swipe gestures
2. Android: same as iOS
3. macOS: three-panel shell, keyboard shortcuts, persistent sidebar, context panel
4. Windows: same as macOS
5. Apple TV: D-pad navigation, content rows, remote controls
6. Android TV: same as Apple TV

### Regression
- Web app (`ui/web`) still works after shared package extraction
- Electron app (`ui/electron`) still works after shared package extraction
