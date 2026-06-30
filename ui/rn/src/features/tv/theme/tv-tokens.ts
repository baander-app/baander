/**
 * TV-specific design tokens for 10-foot viewing distance.
 *
 * Overrides base tokens for Apple TV interface:
 * - Larger typography (24px+ body text, 32px+ headings)
 * - Generous spacing (24-32px gaps vs 16px on mobile)
 * - Larger touch targets for Siri Remote
 *
 * Source: Apple TV Human Interface Guidelines
 */

import { spacing, radii, fontSizes } from '@/shared/theme/tokens';
import { colors } from '@/shared/theme/colors';

export const tvSpacing = {
  ...spacing,
  // TV-specific gaps - larger for 10ft viewing
  gap_xs: spacing[3], // 12px
  gap_sm: spacing[4], // 16px
  gap_md: spacing[6], // 24px
  gap_lg: spacing[8], // 32px
  gap_xl: spacing[10], // 40px

  // Section padding
  sectionPadding: spacing[6], // 24px
  sectionPaddingLarge: spacing[8], // 32px

  // Content row spacing
  rowGap: spacing[4], // 16px between sections
  rowItemGap: spacing[3], // 12px between cards
} as const;

export const tvRadii = {
  ...radii,
  // TV cards have slightly larger radius for visual clarity at distance
  card_sm: radii.lg, // 8px
  card_md: radii.xl, // 12px
  card_lg: radii['2xl'], // 16px
} as const;

export const tvFontSizes = {
  // Override base font sizes for TV (minimum 24pt for body text per Apple HIG)
  xs: 18,
  label: 20,
  sm: 22,
  body: 24, // Minimum readable at 10ft
  lg: 28,
  xl: 32,
  '2xl': 40,
  '3xl': 48,
  '4xl': 56,
  display: 64,
} as const;

export const tvSizes = {
  // Focus indicator
  focusBorderWidth: 3,
  focusGlow: 8,

  // Card sizes
  cardWidth: 280,
  cardHeight: 280,
  cardHeroWidth: 400,
  cardHeroHeight: 400,

  // Touch targets (minimum 48dp for TV)
  touchTarget: 48,
  buttonHeight: 56,
  inputHeight: 56,

  // Layout
  safeZonePercent: 0.9, // 90% of screen for safe area
  headerHeight: 64,
  navBarHeight: 72,
} as const;

export const tvColors = {
  ...colors,

  // Focus indicators - clear visual feedback at 10ft
  focus: '#ffffff',
  focusBorder: '#ffffff',
  focusGlow: 'rgba(255, 255, 255, 0.3)',
  focusShadow: 'rgba(0, 0, 0, 0.5)',

  // Override pure white to prevent halos on older TVs
  textPrimary: '#f1f1f1',
  textSecondary: '#b0b0b0',
  textMuted: '#6b6b6b',
} as const;
