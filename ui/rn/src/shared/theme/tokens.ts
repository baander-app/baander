/**
 * Baander spacing, radius, and sizing tokens.
 *
 * Source of truth: ui/DESIGN.md
 */

export const spacing = {
  0: 0,
  0.5: 2,
  1: 4,
  1.5: 6,
  2: 8,
  3: 12,
  4: 16,
  5: 20,
  6: 24,
  8: 32,
  10: 40,
  12: 48,
  16: 64,
} as const;

export const radii = {
  none: 0,
  sm: 4,
  md: 6,
  lg: 8,
  xl: 12,
  '2xl': 16,
  full: 9999,
} as const;

export const sizes = {
  // Layout
  sidebarCollapsed: 56,
  sidebarExpanded: 224,
  contextPanelMax: 360,
  headerHeight: 48,
  nowPlayingBarHeight: 72,

  // Mobile
  mobileNowPlayingBarHeight: 64,
  mobileTabBarHeight: 56,

  // TV
  tvCardMinTouch: 48,

  // List items
  compactRowHeight: 32,
  standardRowHeight: 48,

  // Grid
  albumCardSize: 180,
} as const;

export const fontSizes = {
  xs: 10,
  label: 11,
  sm: 12,
  body: 14,
  lg: 16,
  xl: 18,
  '2xl': 24,
  '3xl': 30,
} as const;

export const motion = {
  durationFast: 60,
  durationNormal: 80,
  durationSlow: 120,
  easing: 'ease-out' as const,
} as const;
