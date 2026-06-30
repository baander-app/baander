# RN App -- Requirements

**Date:** 2026-05-13
**Status:** Approved
**Brainstorm rounds:** 4

---

## What

React Native app at `ui/rn/` targeting iOS, Android, macOS, and Windows. Full feature parity with the existing web app. Same three-panel design language adapted per form factor.

## Why

- Electron covers Linux. RN covers everything else.
- Single codebase for 4 platforms instead of maintaining separate native apps.
- Bare RN gives full control over audio, native modules, and platform-specific behavior.

## Platform Matrix

| Platform | Framework | Notes |
|----------|-----------|-------|
| iOS | `react-native-tvos` | Same as vanilla RN |
| Android | `react-native-tvos` | Same as vanilla RN |
| Apple TV (tvOS) | `react-native-tvos` | D-pad navigation, focus management |
| Android TV | `react-native-tvos` | D-pad navigation, leanback UI |
| macOS | `react-native-macos` | Microsoft-maintained fork |
| Windows | `react-native-windows` | Microsoft-maintained, XAML |
| Linux | Electron (existing) | No changes -- kept as-is |

Development targets all four RN platforms in parallel from day one.

---

## Architecture

### Core Stack

| Concern | Choice | Rationale |
|---------|--------|-----------|
| Framework | `react-native-tvos` 0.81.5-2 | Drop-in fork of RN with Apple TV + Android TV support |
| Audio | Custom native audio module (AVPlayer/ExoPlayer/MediaPlayer) | No third-party dependency; full control over playback, background audio, media sessions |
| Navigation | React Navigation 7 | De facto standard, drawer/stack/tab support |
| State | Zustand + persist middleware | Matches web app pattern exactly |
| Crypto / DPoP | react-native-quick-crypto | JSI polyfill for Web Crypto API, maintained by Expo team |
| HTTP | Axios | Shared with web app, interceptors for DPoP proof injection |
| API types | Orval-generated from OpenAPI spec | Single spec, shared across all apps |
| Styling | NativeWind v4 (Tailwind for RN) | Same Tailwind classes as web app, adapted to RN primitives |
| Language | TypeScript, ESM | Matches web/electron |

### Shared API Package

Extract from `ui/web/src/shared/` into `ui/shared/`:

```
ui/shared/
  package.json
  api-client/
    gen/
      endpoints.ts        # Orval-generated API functions + types
    axios-instance.ts     # Axios with DPoP interceptors (platform-aware)
    index.ts
  crypto/
    dpop-proof.ts         # JWT signing (platform-aware crypto backend)
    dpop-key-pair.ts      # Key pair generation interface
    dpop-store.ts         # In-memory key pair + nonce cache
  types/
    index.ts              # Shared TypeScript interfaces (Track, etc.)
```

#### Platform-Aware Crypto

The web app uses `crypto.subtle` (Web Crypto API) for ECDSA P-256 signing. RN has no native Web Crypto. Solution:

```typescript
// ui/shared/crypto/platform.ts
import { Platform } from 'react-native' // or 'web' check

export interface CryptoBackend {
  generateKey(algorithm: EcKeyGenParams, extractable: boolean, usages: KeyUsage[]): Promise<CryptoKeyPair>
  exportKey(format: KeyFormat, key: CryptoKey): Promise<JsonWebKey>
  importKey(format: KeyFormat, keyData: JsonWebKey, algorithm: AlgorithmIdentifier, extractable: boolean, usages: KeyUsage[]): Promise<CryptoKey>
  sign(algorithm: EcdsaParams, key: CryptoKey, data: ArrayBuffer): Promise<ArrayBuffer>
  digest(algorithm: AlgorithmIdentifier, data: ArrayBuffer): Promise<ArrayBuffer>
  randomUUID(): string
}
```

Web implements this with `window.crypto.subtle`. RN implements with `react-native-quick-crypto`. Both produce identical DPoP proofs -- verified by shared test vectors.

#### DPoP Key Storage

| Platform | Storage | Notes |
|----------|---------|-------|
| Web | IndexedDB (via `auth-db.ts`) | Non-exportable CryptoKey survives structured clone |
| iOS/macOS | iOS Keychain / macOS Keychain | Via `react-native-keychain` |
| Android | Android Keystore | Via `react-native-keychain` |
| Windows | Windows Credential Manager | Via `react-native-keychain` (if supported) or custom native module |

The key pair is generated once, stored in secure hardware-backed storage, and loaded on app start. This replaces the web's IndexedDB strategy.

#### Axios Instance Adaptation

The current web axios instance (`ui/web/src/shared/api-client/axios-instance.ts`) has these interceptors:

1. **Request interceptor**: Reads `getDpopKeyPair()` from in-memory store, calls `createDpopProof()`, attaches `DPoP` header and `Authorization: DPoP <token>` header
2. **Response interceptor (success)**: Extracts `DPoP-Nonce` from response headers
3. **Response interceptor (error)**: Handles 401 with token refresh, handles `use_dpop_nonce` 400 with retry

All three interceptors work unchanged in RN -- they only depend on Axios (works in RN), the crypto backend (platform-aware), and the auth store (Zustand). The only change is `baseURL` resolution:

```typescript
// Web: (window as any).__BAANDER_API_URL__ || ''
// RN:  stored server URL from settings (AsyncStorage or secure storage)
```

### Project Structure

```
ui/rn/
  package.json
  tsconfig.json
  metro.config.js
  babel.config.js
  react-native.config.js
  app.json                     # App metadata (name, bundle ID, icons)
  index.js                     # Entry point (imports src/app)
  src/
    app/
      App.tsx                  # Root component, navigation providers
      navigation/
        root-navigator.tsx     # Platform-specific root: drawer+stack (mobile) vs sidebar+stack (desktop)
        music-stack.tsx        # Music feature stack
        admin-stack.tsx        # Admin feature stack
        settings-stack.tsx     # Settings stack
        auth-stack.tsx         # Login, register
    features/
      layout/
        components/
          AppShell.tsx          # Three-panel container (flex row desktop, flex col mobile)
          Sidebar.tsx           # Persistent sidebar (desktop) / Drawer content (mobile)
          SidebarSelector.tsx   # Media type selector (Music, Movies, TV, etc.)
          SidebarContent.tsx    # Sectioned navigation items
          SidebarPinnedFooter.tsx
          ContextPanel.tsx      # Slide-in panel (desktop) / Bottom sheet (mobile)
          NowPlayingBar.tsx     # Persistent bottom bar with transport controls
          NowPlayingCompact.tsx # Compact now-playing in context panel
          NowPlayingFull.tsx    # Full-screen album art + controls + lyrics
          SpotlightOverlay.tsx  # Command palette (desktop only)
        stores/
          sidebar-store.ts      # Sidebar collapsed/expanded state
          context-panel-store.ts # Panel mode (closed/compact/expanded), active tab
          media-mode-store.ts   # Active media type (music/movies/tv/etc.)
          lyrics-fullscreen-store.ts
        hooks/
          use-context-panel-selection.ts
          use-media-shortcuts.ts
          use-sidebar-config.ts
      player/
        components/
          ProgressBar.tsx
          QueueModal.tsx
          QueueTab.tsx
          LyricsTab.tsx
          DetailsTab.tsx
          PlayerBar.tsx
        stores/
          player-store.ts       # Zustand: queue, currentIndex, isPlaying, shuffle, repeat, volume
          player-handlers.ts    # Track-player event handlers
        services/
          track-player-service.ts # react-native-track-player setup + control wrapper
        hooks/
          use-audio-playback.ts  # Connects player-store to track-player-service
      catalog/
        pages/
          HomePage.tsx
          AlbumsPage.tsx
          AlbumDetailPage.tsx
          ArtistsPage.tsx
          ArtistDetailPage.tsx
          SongsPage.tsx
          GenresPage.tsx
          SearchPage.tsx
          CatalogShell.tsx
        stores/
          selection-store.ts
          view-mode-store.ts
          list-column-store.ts
      playlist/
        pages/
          PlaylistsPage.tsx
      radio/
        pages/
          RadioPage.tsx
        stores/
          radio-store.ts
        hooks/
          use-radio-audio.ts
      equalizer/
        pages/
          EqualizerPage.tsx
        stores/
          eq-bands-store.ts
          eq-compare-store.ts
          eq-processing-store.ts
          eq-profiles-store.ts
      auth/
        pages/
          LoginPage.tsx
          RegisterPage.tsx
        components/
          ProtectedRoute.tsx
        stores/
          auth-store.ts          # Zustand persist: accessToken, refreshToken, user
      settings/
        pages/
          SettingsPage.tsx
        hooks/
          use-player-preferences.ts
      admin/
        components/
          AdminShell.tsx
          AdminRoute.tsx
        pages/
          AdminDashboardPage.tsx
          JobMonitorPage.tsx
          RateLimitersPage.tsx
          ServerDiagnosticsPage.tsx
          ConfigurationPage.tsx
          UsersPage.tsx
          ScanningPage.tsx
          ActivityPage.tsx
          RecommendationsPage.tsx
          AdminSettingsPage.tsx
      movies/
        pages/
          MoviesHomePage.tsx
      tv/
        pages/
          TVHomePage.tsx
      podcasts/
        pages/
          PodcastsHomePage.tsx
      concerts/
        pages/
          ConcertsHomePage.tsx
      ebooks/
        pages/
          EbooksHomePage.tsx
      notification/
        components/
          NotificationBell.tsx
          NotificationPopout.tsx
        hooks/
          use-notifications.ts
        stores/
          notification-store.ts
    shared/
      components/
        ui/
          button.tsx             # RN Pressable with design system tokens
          card.tsx
          input.tsx              # RN TextInput
          textarea.tsx
          skeleton.tsx
          separator.tsx
          slider.tsx             # @react-native-community/slider
          tabs.tsx               # Custom tab bar
          dialog.tsx             # RN Modal
          sheet.tsx              # Bottom sheet (mobile) / side panel (desktop)
          scroll-area.tsx        # ScrollView / FlatList wrapper
          badge.tsx
          tooltip.tsx            # Desktop only (macOS/Windows)
          context-menu.tsx       # Desktop: right-click. Mobile: long-press.
          toggle.tsx
          switch.tsx
          select.tsx
          filter-bar.tsx
        dashboard-section.tsx
        error-banner.tsx
        ErrorBoundary.tsx
        horizontal-scroll-row.tsx
        LoadingSkeleton.tsx
        media-type-home-page.tsx
      hooks/
        use-image-blob.ts        # Adapted for RN: use cached image from API
        use-keyboard-shortcuts.ts # Desktop only
      utils/
        blurhash.ts
        format-duration.ts
        format-relative-time.ts
      theme/
        tokens.ts                # Color, spacing, typography as RN constants
        colors.ts
        typography.ts
        spacing.ts
      lib/
        mediator/                # Event bus (matching web app pattern)
          store-registry.ts
    platforms/
      ios/
        AppDelegate.mm
        Info.plist
      android/
        MainActivity.kt
        AndroidManifest.xml
      macos/
        AppDelegate.mm
        Info.plist
      windows/
        App.cpp
        Package.appxmanifest
```

---

## Deep Technical Mapping

### Player: Web vs RN

The web player uses an `HTMLAudioElement` managed by a Zustand store. The store holds an `audioElement: HTMLAudioElement | null` reference and calls `.src`, `.play()`, `.pause()` directly. The `AudioService` wraps a Web Audio API `AudioProcessor` for EQ.

For RN, this maps to `react-native-track-player` which provides its own queue/transport model. The mapping:

| Web (HTMLAudioElement) | RN (react-native-track-player) |
|------------------------|-------------------------------|
| `audioElement.src = url` | `TrackPlayer.load({ url })` |
| `audioElement.play()` | `TrackPlayer.play()` |
| `audioElement.pause()` | `TrackPlayer.pause()` |
| `audioElement.currentTime = t` | `TrackPlayer.seekTo(t)` |
| `audioElement.volume = v` | `TrackPlayer.setVolume(v)` |
| `audioElement.ontimeupdate` | `TrackPlayer.addEventListener('playback-progress-updated')` |
| `audioElement.onended` | `TrackPlayer.addEventListener('playback-active-track-changed')` |
| `AudioProcessor` (Web Audio API) | Not available in RN -- EQ via track-player DSP or native module |

The Zustand player-store can remain structurally identical but the `audioElement` reference is replaced by calls to `TrackPlayer` static methods. The store's `playTrack`, `playNext`, `playPrevious` actions call `TrackPlayer` instead of `audioElement`.

Stream URL construction is identical: `/api/stream/track?id={publicId}` -- resolved against the stored server URL.

### Equalizer: Web Audio API vs RN

The web app's `AudioProcessor` connects to Web Audio API with BiquadFilterNodes for EQ bands. This has no RN equivalent. Options:

1. **react-native-track-player DSP** -- if RNTP v2 adds audio processing support
2. **Native module** -- custom native EQ module (Android: `android.media.audiofx.Equalizer`, iOS: `AVAudioUnitEQ`)
3. **Skip for v1** -- disable EQ on RN, show "Desktop only" message

Recommendation: option 3 for initial ship, option 2 as follow-up. EQ is not a launch blocker.

### Sidebar: React Navigation Drawer vs Custom

The web sidebar (`ui/web/src/features/layout/components/Sidebar.tsx`) is a 224px-wide `<aside>` with:
- Logo ("Bander")
- Search input
- Media type selector (`SidebarSelector`) -- Music / Movies / TV / Podcasts / Concerts / E-books
- Sectioned navigation (`SidebarContent`) loaded from config
- Pinned footer (`SidebarPinnedFooter`)
- Settings/Customize button

On mobile, this becomes a React Navigation drawer with the same component tree. On desktop, it renders as a permanent sidebar in the AppShell flex row. The component code is shared -- only the container differs:

```typescript
// Platform-specific rendering
const isDesktop = Platform.OS === 'macos' || Platform.OS === 'windows';

// Desktop: Sidebar renders inline in flex row
// Mobile: Sidebar renders inside NavigationContainer drawer
```

### Context Panel: Fixed Panel vs Bottom Sheet

The web context panel (`ui/web/src/features/layout/components/ContextPanel.tsx`) is a resizable `<aside>` (280-600px) with tabs (Queue, Lyrics, Details, Edit), notification bell, and a `PlayerBar` at the bottom. It has two modes:
- **Compact**: Fixed 280px, shows `NowPlayingCompact`
- **Expanded**: Resizable 280-600px, shows tabbed interface + `PlayerBar`

On mobile:
- Compact mode becomes the `NowPlayingBar` at the bottom of the screen
- Expanded mode becomes a full-screen modal (like Spotify's full now-playing view)
- Tabs (Queue, Lyrics, Details) are accessible via a swipe-up sheet from the now-playing bar

On desktop:
- Same as web -- fixed panel, resizable, with tabs

### AppShell: Platform-Adaptive Layout

The web `AppShell` is a single `<div className="flex h-screen">` with Sidebar, Outlet, and ContextPanel as children. The RN equivalent:

```typescript
function AppShell() {
  const isDesktop = Platform.OS === 'macos' || Platform.OS === 'windows';

  if (isDesktop) {
    return (
      <View style={styles.desktopShell}>
        <Sidebar />
        <View style={styles.mainContent}>
          <Outlet />  {/* React Navigation screen */}
        </View>
        <ContextPanel />
      </View>
    );
  }

  // Mobile: sidebar is drawer, context panel is modal
  return (
    <Drawer.Navigator drawerContent={() => <Sidebar />}>
      <Drawer.Screen name="Main" component={MobileMain} />
    </Drawer.Navigator>
  );
}
```

### State Stores: 1:1 Mapping

All web Zustand stores map directly to RN. They use the same `persist` middleware with platform-specific storage:

| Web Storage | RN Storage |
|-------------|-----------|
| `localStorage` (via zustand persist) | `@react-native-async-storage/async-storage` |
| `IndexedDB` (for DPoP keys) | `react-native-keychain` (secure storage) |
| `window.crypto.subtle` | `react-native-quick-crypto` |

Stores to port (exact structural copies):
- `auth-store.ts` -- tokens, user, initAuth(), setTokens(), clearAuth()
- `player-store.ts` -- queue, playback state, transport controls
- `sidebar-store.ts` -- collapsed, editor open
- `context-panel-store.ts` -- mode (closed/compact/expanded), active tab, width
- `media-mode-store.ts` -- activeMedia (music/movies/tv/etc.)
- `notification-store.ts`
- `selection-store.ts`, `view-mode-store.ts`, `list-column-store.ts`
- `eq-bands-store.ts`, `eq-compare-store.ts`, `eq-processing-store.ts`, `eq-profiles-store.ts`
- `radio-store.ts`
- `dpop-store.ts` -- in-memory key pair + nonce cache (same module, different storage backend)

### Shadcn/UI Components: Tailwind CSS to NativeWind

The web app uses shadcn/ui components (React + Radix UI + Tailwind). None of these work in RN. Each needs an RN equivalent:

| Web (shadcn/Radix) | RN Replacement | Notes |
|--------------------|---------------|-------|
| `Button` | `Pressable` + NativeWind classes | Direct mapping |
| `Input` | `TextInput` | Direct mapping |
| `Card` | `View` with card styles | Direct mapping |
| `Dialog` | `Modal` | React Native built-in |
| `Sheet` | `@gorhom/bottom-sheet` | Mobile; desktop uses `Modal` or animated `View` |
| `DropdownMenu` | Custom (desktop: popover, mobile: action sheet) | |
| `ContextMenu` | Desktop: right-click handler. Mobile: `Pressable onLongPress` | |
| `Tabs` | Custom tab bar | `Tab` component with `Pressable` triggers |
| `Tooltip` | Desktop only: `Pressable` with delay | |
| `ScrollArea` | `ScrollView` / `FlatList` | Direct mapping |
| `Slider` | `@react-native-community/slider` | |
| `Skeleton` | `Animated.View` with pulse | |
| `Command` | `SpotlightOverlay` desktop only | May need custom implementation |
| `Select` | Custom picker or `@react-native-community/picker` | |
| `Switch` | `Switch` | RN built-in |
| `Separator` | `View` with border | Direct mapping |
| `Badge` | `Text` in styled `View` | Direct mapping |
| `Toggle` | `Pressable` with active state | |
| `Toaster` (sonner) | Custom toast with `react-native-toast-message` or custom | |

### Keyboard Shortcuts

The web app uses `useKeyboardShortcuts` and `useMediaShortcuts` hooks. On desktop (macOS/Windows), RN supports keyboard events natively. Mapping:

```typescript
// Desktop only
if (Platform.OS === 'macos' || Platform.OS === 'windows') {
  // Use onKeyDown on focused View, or react-native-keychain for menu bar integration
  // macOS: integrate with NSApplication menu bar via native module
  // Windows: integrate with system menu via native module
}
```

The web app's `accelerators.ts` pattern (Electron menu accelerators) provides the shortcut definitions. The RN desktop apps should define the same shortcuts via native menu bar modules.

### Server Discovery

The Electron app has `discovery.service.ts` using mDNS/Bonjour. For RN:
- iOS/macOS: `react-native-zeroconf` or custom NetService native module
- Android: `jmDNS` via native module or `react-native-zeroconf`
- Windows: Custom native module or manual network scanning

Same discovery protocol as Electron -- mDNS with `_baander._tcp` service type.

### Deep Linking

The Electron app has `deep-link.service.ts` handling `baander://` URLs. For RN:
- iOS: Custom URL scheme in `Info.plist`
- Android: Intent filter in `AndroidManifest.xml`
- macOS: `CFBundleURLTypes` in `Info.plist`
- Windows: Protocol activation in `Package.appxmanifest`

All platforms route to the same navigation handler.

### Image Loading (Blurhash)

The web app uses `useImageBlob` hook + blurhash decoding. For RN:
- Cover images: `<Image>` with `react-native-fast-image` for caching
- Blurhash: `react-native-blurhash` (native module, all platforms)
- Placeholder: Render blurhash while image loads

Same blurhash values from the API, native rendering.

---

## Design Language

Port the existing design system from `ui/DESIGN.md` to RN:

### Tokens

| Token | Web | RN |
|-------|-----|----|
| Background | `#000000` | `Colors.background` |
| Card | `#0a0a0b` | `Colors.card` |
| Sidebar | `#080809` | `Colors.sidebar` |
| Border | `#1a1a1f` | `Colors.border` |
| Muted foreground | `#8b8d97` | `Colors.mutedForeground` |
| Font | Inter | Inter (bundled as asset) |
| Body size | 14px | 14 |
| Label size | 11px | 11 |
| Tracking | `-0.01em` | `-0.25` (RN letterSpacing is in dp, not em -- scale by fontSize) |
| Border radius | 8px / 12px | 8 / 12 |

### Layout -- Three Distinct UIs

The app has three separate UI layers, each purpose-built for its form factor. All three share the same Zustand stores, API client, audio module, and design tokens (colors, typography).

**1. Desktop (macOS/Windows) -- Three-panel shell (matching web app)**

| Component | Desktop |
|-----------|----------|
| Layout | Three-panel: Sidebar (224px) | Content (flex) | Context Panel (280-600px) |
| Sidebar | Persistent, collapsible |
| Context panel | Slide-in, resizable |
| Now-playing bar | Fixed bottom bar, 72px |
| Navigation | Sidebar links + keyboard shortcuts |
| Search | Inline sidebar search input |
| Media type selector | Sidebar selector tabs |

**2. Mobile (iOS + Android) -- Dedicated mobile UI**

The mobile apps have their own unique UI, shared between iOS and Android. Not a compressed version of the desktop three-panel layout -- a purpose-built touch-first interface.

| Component | Mobile UI |
|-----------|-----------|
| Layout | Full-screen content, bottom tab navigation |
| Navigation | Bottom tab bar (Home, Search, Library, Settings) + stack navigation |
| Now-playing | Mini-player bar above tab bar; full-screen expand on tap |
| Context actions | Swipe gestures on list items (add to queue, add to playlist) |
| Album detail | Full-screen with cover art header, track list below |
| Artist detail | Hero image header, content sections below |
| Search | Full-screen search with recent searches + results |
| Queue | Accessible via now-playing bar or tab |
| Sidebar | No sidebar -- replaced by bottom tabs |
| Context panel | Replaced by full-screen now-playing view |
| Gestures | Swipe left/right on now-playing for next/prev, pull-to-refresh on lists |
| Media type | Top segmented control on home screen (Music, Movies, TV, etc.) |

**3. TV (Apple TV + Android TV) -- Dedicated 10-foot UI**

The TV apps have their own unique UI, shared between Apple TV and Android TV. Purpose-built for D-pad navigation at 10-foot viewing distance.

| Component | TV UI |
|-----------|-------|
| Layout | Full-screen browse, no panels |
| Navigation | Top bar (logo + media tabs + search) + D-pad |
| Content | Horizontal content rows (Netflix-style) |
| Hero | Large featured content section at top |
| Detail | Full-screen album/artist detail |
| Now-playing | Overlay on browse content (dismissed by Back) |
| Search | On-screen keyboard + voice search |
| Focus | D-pad (left/right in row, up/down between rows, enter selects) |
| Cards | Large focusable cards with highlight border |

**Shared across all three UIs:**
- Zustand stores (auth, player, catalog, etc.)
- API client (`ui/shared/`)
- Custom native audio module
- Color tokens (#000, #0a0a0b, #080809, #1a1a1f, #8b8d97)
- Typography (Inter)
- Flat, no-gradient, no-shadow design language

**Separate per UI layer:**
- AppShell and navigation structure
- All UI components (cards, lists, bars, overlays)
- Spacing/sizing tokens
- Interaction patterns (click vs tap vs D-pad)

### Interaction Model (unchanged)

- Primary actions: single click/tap
- Selection: single click/tap, multi via Cmd/Ctrl+click (desktop) or long-press (mobile)
- Context menus: right-click (desktop), long-press (mobile)
- No "..." menu buttons on items
- No gradients, no drop shadows except overlays
- No bounce/spring animations. ease-out only, <120ms

### Components to Port

All from web app:
- Primitives: Button, Input, Textarea, ScrollView, Sheet, Command palette (desktop)
- Lists and grids: FlatList with virtualization, GridLayout
- Cards: Album cards, artist cards, song rows
- Loading skeletons (Animated.View with pulse)
- Error states
- Empty states
- Filter bars
- Tables (desktop only -- RN FlatList on mobile)

---

## Feature Parity Checklist

Based on current web app routes:

### Music
- [ ] Home page (recent, recommendations)
- [ ] Albums browse + album detail
- [ ] Artists browse + artist detail
- [ ] Songs browse
- [ ] Catalog shell (genre/mood browsing)
- [ ] Genres page
- [ ] Search
- [ ] Playlists (create, edit, add/remove songs)
- [ ] Radio
- [ ] Equalizer (disabled on RN for v1, desktop may support via native module)

### Other Media
- [ ] Movies home + browse
- [ ] TV shows home + browse
- [ ] Podcasts home + browse
- [ ] Concerts home + browse
- [ ] E-books home + browse

### Playback
- [ ] Play/pause/next/previous
- [ ] Queue management (add, remove, reorder)
- [ ] Shuffle/repeat
- [ ] Seek/scrub
- [ ] Background audio (media session integration)
- [ ] Lock screen controls (iOS/Android)
- [ ] System media key support (macOS/Windows)
- [ ] Gapless playback (if supported by RNTP)
- [ ] Crossfade (if supported by RNTP)
- [ ] Stream URL: `/api/stream/track?id={publicId}` with DPoP auth headers

### Player UI
- [ ] Now-playing bar (persistent bottom, all platforms)
- [ ] Full-screen now-playing view (album art, controls, lyrics)
- [ ] Mini player (compact context panel mode)
- [ ] Queue tab, lyrics tab, details tab in context panel

### Auth
- [ ] Login page
- [ ] Register page
- [ ] Token refresh with DPoP proof
- [ ] DPoP proof generation (ES256 / P-256)
- [ ] Credential storage: Keychain (iOS/macOS), Keystore (Android), Credential Manager (Windows)
- [ ] 401 handling with queued retry (matching web interceptor pattern)
- [ ] `use_dpop_nonce` error retry (matching web pattern)

### Settings
- [ ] Server configuration (URL entry + discovery)
- [ ] Playback settings
- [ ] Display settings
- [ ] Theme (dark only for v1)
- [ ] Sidebar customization (matching web SidebarEditor)

### Admin
- [ ] Dashboard
- [ ] Job monitor
- [ ] Rate limiters
- [ ] Server diagnostics
- [ ] Configuration
- [ ] Users management
- [ ] Scanning trigger
- [ ] Activity log
- [ ] Recommendations config
- [ ] Admin settings

### General
- [ ] Deep linking (`baander://`)
- [ ] Server discovery (mDNS `_baander._tcp`)
- [ ] Image loading with blurhash placeholders
- [ ] Notification bell + SSE/WebSocket notifications
- [ ] Offline mode (cached library metadata -- follow-up)

---

## Key Technical Decisions

### Audio: Custom Native Module

- Build per-platform native audio modules instead of relying on react-native-track-player
- **iOS/macOS:** `AVPlayer` (Swift) -- `AVAudioSession` for background, `MPNowPlayingInfoCenter` for lock screen, `MPRemoteCommandCenter` for transport controls
- **Android:** `ExoPlayer` (Kotlin) -- `MediaSession` for notification/lock screen, `MediaBrowserServiceCompat` for background service
- **Windows:** `MediaPlayer` via C++/WinRT -- `SystemMediaTransportControls` for taskbar/lock screen
- Unified JS API via RN Turbo Native Module + NativeEventEmitter
- No dependency on third-party audio libraries that could get abandoned

### Crypto: react-native-quick-crypto

- JSI-based, synchronous for key operations, async-compatible for subtle crypto
- Implements `crypto.subtle.generateKey`, `crypto.subtle.sign`, `crypto.subtle.exportKey`, `crypto.subtle.importKey`, `crypto.subtle.digest`
- API-identical to Web Crypto -- the DPoP proof code in `ui/shared/crypto/dpop-proof.ts` works unchanged
- Key pair storage: secure hardware storage via `react-native-keychain`, replacing IndexedDB

### Native Module Gaps

Known packages that lack macOS/Windows implementations:

| Package | macOS | Windows | Strategy |
|---------|-------|---------|----------|
| `@gorhom/bottom-sheet` | Partial | None | Use RN `Modal` + `Animated` as fallback on desktop |
| `react-native-gesture-handler` | Fork exists | Fork exists | Use official forks + patch-package |
| `react-native-blur` | Limited | None | Skip blur on unsupported platforms |
| `react-native-fast-image` | Supported | Supported | No gap |
| `react-native-track-player` | Supported | Partial | Evaluate desktop support at implementation time |
| `react-native-keychain` | Supported | Supported | No gap |

Strategy: fork and bridge where effort < 1 week, accept visual/functional limitations where not. Desktop users have keyboard/mouse alternatives for gesture-based interactions.

### Navigation

React Navigation 7 with platform-adaptive root:

```
Desktop:
  NavigationContainer
    Stack.Navigator
      Screen: AppShell (contains Sidebar + Outlet + ContextPanel inline)
        Stack.Navigator (nested)
          Screen: Home, Albums, AlbumDetail, etc.

Mobile:
  NavigationContainer
    Drawer.Navigator (sidebar content)
      Tab.Navigator (bottom tabs: Home, Search, Library, Settings)
        Stack.Navigator per tab
          Screen: Home, Albums, AlbumDetail, etc.
    Modal: NowPlayingFull (full-screen, swipe down to dismiss)
```

---

## File Changes

### New

| Path | Purpose |
|------|---------|
| `ui/rn/` | Entire RN app (~200+ files) |
| `ui/shared/` | Shared API client, crypto, types (extracted from ui/web) |

### Modified

| Path | Change |
|------|--------|
| `ui/web/src/shared/api-client/axios-instance.ts` | Import crypto from `ui/shared/crypto/` |
| `ui/web/src/shared/crypto/dpop-proof.ts` | Move to `ui/shared/crypto/`, add platform detection |
| `ui/web/src/shared/crypto/dpop-key-pair.ts` | Move to `ui/shared/crypto/`, add platform detection |
| `ui/web/src/shared/crypto/dpop-store.ts` | Move to `ui/shared/crypto/` |
| `ui/web/src/shared/crypto/auth-db.ts` | Move to `ui/shared/crypto/` |
| `ui/web/package.json` | Add workspace reference to `ui/shared` |
| `ui/electron/package.json` | Add workspace reference to `ui/shared` |
| `ui/web/src/shared/api-client/gen/` | Re-export from `ui/shared/api-client/gen/` for backward compat |

### Unchanged

| Path | Reason |
|------|--------|
| `ui/electron/` | Retained for Linux, no functional changes |
| `ui/DESIGN.md` | Source of truth for design system |
| `ui/ICON-GUIDE.md` | Icon usage guide |
| Backend API | No changes needed |

---

## Risks

| Risk | Impact | Mitigation |
|------|--------|-----------|
| macOS/Windows native module gaps | Some packages lack desktop implementations | Fork where reasonable, use fallbacks |
| react-native-track-player desktop support | Replaced by custom native audio module | Full control, no third-party dependency |
| 4-platform testing matrix | High surface area for regressions | Shared component tests + platform-specific E2E |
| Shared package extraction breaking web/electron | Import path changes could break existing apps | Backward-compatible re-exports during migration |
| DPoP crypto polyfill correctness | `react-native-quick-crypto` must produce identical proofs | Unit tests with shared test vectors |
| EQ not available on RN | Web Audio API has no RN equivalent | Disable for v1, native module follow-up |
| NativeWind v4 maturity | Tailwind-for-RN may have rendering edge cases | Fallback to StyleSheet if needed |

## Verification

- All platforms launch with the correct UI layer (desktop: three-panel, mobile: tabs, TV: rows)
- Audio playback with background + media session works on iOS and Android
- Keyboard shortcuts work on macOS and Windows
- D-pad navigation works on Apple TV and Android TV
- DPoP auth flow completes on all platforms (login, token refresh, API calls)
- Desktop three-panel shell matches web app layout
- Mobile mini-player, full-screen now-playing, swipe gestures work
- TV content rows, hero section, remote controls work
- Zustand stores persist and hydrate correctly per platform
- Stream URL auth works (DPoP header on `/api/stream/track` requests)
