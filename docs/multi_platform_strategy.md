# Multi-Platform Support Strategy

## Executive Summary

This document outlines the approach for adding **iOS**, **Android**, and **Apple TV** support to Bånder while maintaining a unified codebase with the existing web and Electron applications.

## Recommended Approach: Monorepo with React Native

### Architecture Overview

Transform the project into a **Turborepo monorepo** with the following structure:

```
baander/
├── apps/
│   ├── web/                    # Current Vite/React app (desktop & browser)
│   ├── electron/               # Current Electron app
│   ├── mobile/                 # New: React Native app (iOS & Android)
│   └── apple-tv/               # New: React Native app for Apple TV (tvOS)
│
├── packages/
│   ├── shared/                 # Shared business logic & utilities
│   │   ├── api-client/        # Auto-generated API client (Orval)
│   │   ├── store/             # Redux store & slices
│   │   ├── hooks/             # Custom React hooks
│   │   ├── utils/             # Shared utilities
│   │   ├── types/             # TypeScript types & interfaces
│   │   ├── constants/         # App constants
│   │   └── i18n/              # Translations & i18next config
│   │
│   ├── ui-web/                 # Web-specific UI components
│   │   ├── components/        # Radix UI, web components
│   │   ├── layouts/           # Web layouts
│   │   └── styles/            # Global styles, SCSS
│   │
│   └── ui-native/              # React Native components
│       ├── components/        # Native mobile components
│       ├── navigation/        # React Navigation setup
│       └── themes/            # Native theming
│
├── package.json                # Root package.json (Turborepo config)
├── turbo.json                 # Turborepo configuration
└── pnpm-workspace.yaml        # or npm workspaces
```

## Why This Approach?

### Advantages

1. **Maximum Code Sharing**
   - Business logic, state management, API client, hooks, and utilities shared 100%
   - Only UI components differ per platform
   - Single source of truth for data fetching and state

2. **Platform-Optimized UX**
   - Web: Radix UI components with mouse/keyboard optimization
   - Mobile: React Native components with touch gestures
   - Apple TV: tvOS-optimized components with Siri Remote focus engine

3. **Type Safety Across Platforms**
   - Shared TypeScript types ensure API contracts are consistent
   - Changes to API types propagate to all platforms

4. **Simplified Maintenance**
   - One API client generator (Orval) serves all platforms
   - Bug fixes in shared logic automatically benefit all platforms
   - Single test suite for business logic

5. **Developer Experience**
   - Turborepo for fast builds and cached tasks
   - Shared linting, formatting, and tooling
   - Consistent development workflow

### Alternatives Considered

| Approach | Pros | Cons | Verdict |
|----------|------|------|---------|
| **Capacitor** (wrap web app) | Quick implementation, share all code | Not truly native, poor Apple TV support, limited native features | ❌ Not suitable for Apple TV |
| **Flutter** | Great performance, excellent multi-platform support | Complete rewrite, lose existing React codebase | ❌ Too expensive |
| **Expo (React Native)** | Best DX, supports tvOS, great tooling | Some native modules require ejecting | ✅ **Recommended** |
| **Progressive Web App** | Easiest, no native code | Limited native features, poor App Store experience | ❌ Not production-ready |

## Implementation Plan

### Phase 1: Monorepo Migration (2-3 weeks)

**Goal:** Restructure project into Turborepo without breaking existing apps

1. **Setup Turborepo**
   ```bash
   # Install Turborepo
   npm install -D turbo

   # Create monorepo structure
   mkdir -p apps/{web,electron} packages/{shared,ui-web,ui-native}
   ```

2. **Move Web App**
   - Move `resources/app/` → `apps/web/src/`
   - Extract shared code to `packages/shared/`
   - Update imports to use workspace packages

3. **Refactor Shared Code**
   ```
   packages/shared/
   ├── api-client/          # Auto-generated from Orval
   ├── store/               # Redux slices (player, queue, etc.)
   ├── hooks/               # useAuth, usePlayer, etc.
   ├── utils/               # formatters, validators
   ├── types/               # API types, models
   └── constants/           # config, endpoints
   ```

4. **Configure Workspaces**
   - `turbo.json`: Build pipeline and caching
   - `pnpm-workspace.yaml`: Workspace definitions
   - Update `package.json` scripts

5. **Update Build Pipeline**
   ```json
   // turbo.json
   {
     "pipeline": {
       "build": { "dependsOn": ["^build"] },
       "dev": { "cache": false },
       "test": { "outputs": ["coverage/**"] }
     }
   }
   ```

### Phase 2: React Native Setup (1-2 weeks)

**Goal:** Create mobile app foundation with shared code

1. **Initialize Expo App**
   ```bash
   npx create-expo-app apps/mobile --template blank-typescript
   ```

2. **Install Core Dependencies**
   ```bash
   cd apps/mobile
   npm install @react-navigation/native react-native-screens react-native-safe-area-context
   npm install @tanstack/react-query @reduxjs/toolkit react-redux redux-persist
   npm install i18next react-i18next
   ```

3. **Configure TypeScript**
   - Use `packages/shared/types` for all type definitions
   - Share ESLint/Prettier config from root

4. **Setup Navigation**
   - React Navigation (native equivalent to react-router-dom)
   - Tab navigation: Browse, Search, Library, Playlists, Settings
   - Stack navigation for drill-down screens

5. **Integrate Shared Packages**
   ```typescript
   // apps/mobile/app.tsx
   import { store } from '@baander/shared/store';
   import { Provider } from 'react-redux';
   import { QueryClientProvider } from '@tanstack/react-query';
   import { useAlbumsIndex } from '@baander/shared/api-client';
   ```

### Phase 3: Build Mobile UI Components (3-4 weeks)

**Goal:** Implement native UI using shared business logic

1. **Create Design System**
   ```
   packages/ui-native/
   ├── components/
   │   ├── AlbumGrid/        # Virtualized grid for albums
   │   ├── SongList/         # Swipeable list with actions
   │   ├── PlayerBar/        # Mini player at bottom
   │   ├── FullPlayer/       # Full-screen player
   │   ├── SearchBar/        # Native search input
   │   └── TabBar/           # Bottom tab navigation
   └── themes/
       ├── colors.ts
       └── typography.ts
   ```

2. **Implement Core Screens**
   - Browse tabs (Albums, Artists, Songs, Playlists)
   - Album detail screen (tracklist, play button, shuffle)
   - Artist detail screen (albums, top songs)
   - Player screen (controls, queue, scrubbing)
   - Settings screens

3. **Media Playback**
   - Use `react-native-track-player` or `expo-av`
   - Sync with backend queue API
   - Background playback support
   - Handle play/pause/skip/seek from lock screen

4. **Authentication**
   - OAuth 2.0 flow (using `expo-auth-session`)
   - WebAuthn support (limited on mobile, use biometrics fallback)
   - Secure credential storage (Keychain/Keystore)

### Phase 4: Apple TV Support (2-3 weeks)

**Goal:** Adapt mobile app for tvOS

1. **Create Apple TV App**
   ```bash
   # Use Expo with tvOS support
   npx create-expo-app apps/apple-tv --template blank-typescript
   ```

2. **TV-Specific Adjustments**
   - Focus engine for Siri Remote navigation
   - Larger touch targets (minimum 80x80px)
   - Horizontal scrolling for long lists
   - Card-based layouts instead of lists
   - Top shelf integration (quick access)

3. **Navigation**
   - Tab navigation works well on Apple TV
   - Focus-based movement (no touch/swipe)
   - Quick actions menu (long press)

4. **UI Components**
   ```typescript
   // Reuse mobile components with TV-specific styling
   import { AlbumGrid } from '@baander/ui-native';
   import { useTVFocus } from '@baander/ui-native/hooks';

   <AlbumGrid
     columns={6}           // More columns on TV
     itemSize="large"      // Larger cards
     focusable={true}      // Enable focus engine
   />
   ```

5. **Player Optimization**
   - Full-screen album art with parallax effect
   - Siri Remote play/pause/skip
   - Background video/lyrics display

### Phase 5: Platform-Specific Features (2-3 weeks)

**Goal:** Implement native capabilities unique to each platform

1. **Mobile-Only Features**
   - Pull-to-refresh (gesture)
   - Swipe-to-delete (playlists/queue)
   - Biometric authentication (Face ID/Touch ID)
   - Push notifications (new releases, downloads)
   - Offline mode (download tracks for offline playback)
   - Cast to Chromecast/AirPlay
   - Background sync

2. **Apple TV-Only Features**
   - Top shelf integration (recent albums, continue playing)
   - Picture-in-picture support
   - AirPlay 2 support
   - Voice search (Siri integration)

3. **Web/Electron-Only Features**
   - Keyboard shortcuts
   - Multiple windows/library management
   - Desktop notifications
   - File drag-and-drop
   - Advanced audio settings

## Shared vs Platform-Specific Code

### What Should Be Shared (100%)

**`packages/shared/`**
- ✅ API client (auto-generated from Orval)
- ✅ Redux store slices (player, queue, user settings)
- ✅ Custom hooks (useAuth, usePlayer, useLibrary)
- ✅ Utility functions (formatters, validators, constants)
- ✅ TypeScript types (API types, models)
- ✅ i18n translations (all language files)
- ✅ Business logic (sorting, filtering, recommendations)

### What Should Be Platform-Specific

**Web (`apps/web/src/`)**
- 🎨 Radix UI components
- 🎨 SCSS modules / CSS-in-JS
- 🎨 react-router-dom routes
- 🎨 Desktop-specific layouts

**Mobile (`apps/mobile/src/`)**
- 🎨 React Native components
- 🎨 React Navigation
- 🎨 Native gestures (swipe, long press, pull-to-refresh)

**Apple TV (`apps/apple-tv/src/`)**
- 🎨 tvOS-optimized layouts
- 🎨 Focus engine integration
- 🎨 Siri Remote handlers

## Technology Stack

### Mobile & Apple TV

| Category | Technology |
|----------|-----------|
| Framework | **React Native** (via Expo SDK 52+) |
| Navigation | **React Navigation** v7 |
| State | Redux Toolkit (shared with web) |
| Data Fetching | TanStack Query (shared with web) |
| UI Components | React Native Paper / NativeBase |
| Media Playback | `react-native-track-player` or `expo-av` |
| Authentication | `expo-auth-session`, `expo-web-browser` |
| Biometrics | `expo-local-authentication` |
| Secure Storage | `expo-secure-store` |
| Notifications | `expo-notifications` |
| Background Tasks | `expo-task-manager` |
| Build & Deploy | **EAS Build** (Expo Application Services) |

### Monorepo

| Category | Technology |
|----------|-----------|
| Workspace | **Turborepo** (or Nx for more features) |
| Package Manager | **pnpm** (fastest, most efficient) |
| TypeScript | Project references with `paths` mapping |
| Linting | ESLint (shared config) |
| Formatting | Prettier (shared config) |
| Testing | Vitest (web), Jest (native) |

## API Client Strategy

### Keep Existing Setup (It's Perfect!)

Your current Orval + Scramble setup is **exactly** what you need:

```
┌─────────────────────────────────────────────────────────┐
│  Backend (Laravel)                                      │
│  ├─ Scramble generates api.json (OpenAPI spec)          │
│  └─ Auto-docs at /api/docs                              │
└─────────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│  packages/shared/api-client/                            │
│  ├─ Orval generates TypeScript client from api.json     │
│  ├─ React Query hooks                                   │
│  └─ Shared with ALL platforms                           │
└─────────────────────────────────────────────────────────┘
```

**No changes needed!** Just move the generated client to `packages/shared/api-client/`.

## Development Workflow

### Commands

```bash
# Install all dependencies
pnpm install

# Development (run specific app)
pnpm --filter web dev          # https://baander.test
pnpm --filter electron dev     # Open Electron app
pnpm --filter mobile dev       # Start Expo dev server
pnpm --filter apple-tv dev     # Start Apple TV simulator

# Build all apps
pnpm build

# Build specific app
pnpm --filter web build
pnpm --filter mobile build     # EAS build
pnpm --filter apple-tv build   # EAS build

# Generate API client (affects all platforms)
pnpm --filter web generate-api-client
pnpm build                     # Rebuild dependent packages

# Type checking across all apps
pnpm tsc

# Linting
pnpm lint

# Testing
pnpm test
```

### Turborepo Caching

```json
// turbo.json
{
  "$schema": "https://turbo.build/schema.json",
  "pipeline": {
    "build": {
      "dependsOn": ["^build"],
      "outputs": ["dist/**", ".next/**", "build/**"]
    },
    "dev": {
      "cache": false,
      "persistent": true
    },
    "generate-api-client": {
      "outputs": ["api-client/generated/**"]
    }
  }
}
```

## Deployment Strategy

### Web
- **No changes** - existing Vite build
- Deploy via existing Docker setup

### Electron
- **No changes** - existing electron-builder setup
- GitHub Releases for auto-updates

### Mobile (iOS & Android)

**Option 1: EAS Build (Recommended)**
```bash
# Configure EAS
eas build:configure

# Build for iOS
eas build --platform ios

# Build for Android
eas build --platform android

# Submit to stores
eas submit --platform ios
eas submit --platform android
```

**Option 2: Local Build**
- Use Xcode (iOS) and Android Studio (Android)
- More control, slower builds

### Apple TV

**Requires Apple Developer Account**
- Build with Xcode on macOS
- Use Expo EAS for tvOS builds
- Submit to App Store (tvOS section)

## Migration Checklist

### Phase 1: Monorepo Setup
- [ ] Install Turborepo and configure workspaces
- [ ] Move web app to `apps/web/`
- [ ] Move Electron app to `apps/electron/`
- [ ] Create `packages/shared/` structure
- [ ] Extract shared code from web app
- [ ] Update all imports to use workspace packages
- [ ] Configure TypeScript project references
- [ ] Update CI/CD pipeline

### Phase 2: Shared Code Refactoring
- [ ] Move API client to `packages/shared/api-client/`
- [ ] Move Redux store to `packages/shared/store/`
- [ ] Move hooks to `packages/shared/hooks/`
- [ ] Move utilities to `packages/shared/utils/`
- [ ] Move types to `packages/shared/types/`
- [ ] Move i18n to `packages/shared/i18n/`
- [ ] Test web and Electron still work

### Phase 3: Mobile App
- [ ] Create Expo app in `apps/mobile/`
- [ ] Install dependencies (React Navigation, Redux, etc.)
- [ ] Configure TypeScript paths
- [ ] Setup navigation structure
- [ ] Implement authentication
- [ ] Build core screens (Browse, Album, Artist, Player)
- [ ] Integrate shared API client and Redux
- [ ] Test on iOS simulator
- [ ] Test on Android emulator

### Phase 4: Apple TV App
- [ ] Create tvOS app in `apps/apple-tv/`
- [ ] Reuse mobile components with TV styling
- [ ] Implement focus engine for Siri Remote
- [ ] Optimize layouts for 10-foot UI
- [ ] Test on Apple TV simulator

### Phase 5: Polish & Deployment
- [ ] Implement platform-specific features
- [ ] Add offline mode for mobile
- [ ] Setup EAS build and deployment
- [ ] Configure auto-updates
- [ ] Test on physical devices
- [ ] Submit to App Stores (iOS, Android, tvOS)

## Potential Challenges & Solutions

### Challenge 1: Media Playback Differences
**Problem:** Web uses HTML5 audio, mobile needs native player.

**Solution:**
```typescript
// packages/shared/player/
// Abstract player interface
interface IPlayer {
  play(url: string): Promise<void>;
  pause(): void;
  seek(time: number): void;
}

// apps/web/src/player/PlayerWeb.ts
// apps/mobile/src/player/PlayerNative.ts
// apps/apple-tv/src/player/PlayerTV.ts
```

### Challenge 2: Authentication Flow
**Problem:** OAuth flow differs per platform (web vs native).

**Solution:**
- Use `expo-auth-session` for mobile/TV
- Keep web flow as-is
- Shared token storage logic

### Challenge 3: Navigation Differences
**Problem:** react-router-dom vs React Navigation.

**Solution:**
- Create a navigation adapter in `packages/shared/navigation/`
- Abstract route definitions
- Platform-specific router implementation

### Challenge 4: Styling Paradigms
**Problem:** SCSS vs React Native StyleSheet.

**Solution:**
- Keep styles completely separate
- Shared design tokens (colors, spacing, typography)
- Use `react-native-platform-color` for CSS variables

### Challenge 5: WebAuthn on Mobile
**Problem:** Passkeys support varies by platform.

**Solution:**
- Fallback to biometric auth on mobile
- Use `expo-local-authentication`
- Keep WebAuthn for web/Electron

## Estimated Timeline

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1: Monorepo Migration | 2-3 weeks | None |
| Phase 2: React Native Setup | 1-2 weeks | Phase 1 |
| Phase 3: Mobile UI | 3-4 weeks | Phase 2 |
| Phase 4: Apple TV | 2-3 weeks | Phase 3 |
| Phase 5: Polish & Deploy | 2-3 weeks | Phase 4 |
| **Total** | **10-15 weeks** | |

## Cost Considerations

### Apple Developer Program
- **$99/year** - Required for iOS/tvOS App Store distribution
- Includes TestFlight for beta testing

### Google Play Console
- **$25 one-time** - Required for Play Store distribution
- Includes internal testing tracks

### Expo/EAS (Optional but Recommended)
- **Free tier**: 15 builds/month
- **Paid**: $29/month for unlimited builds
- Worth it for faster iteration

## Next Steps

1. **Review this plan** and decide on approach
2. **Proof of Concept**: Create minimal mobile app with shared API client
3. **Test the workflow** before full migration
4. **Start Phase 1** when ready

## Resources

- [Turborepo Documentation](https://turbo.build/repo/docs)
- [Expo Documentation](https://docs.expo.dev/)
- [React Navigation](https://reactnavigation.org/)
- [React Native Paper](https://reactnativepaper.com/)
- [EAS Build](https://docs.expo.dev/eas/)
- [React Native TV Support](https://github.com/react-native-community/react-native-tvos)
